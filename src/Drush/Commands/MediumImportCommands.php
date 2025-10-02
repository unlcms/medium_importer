<?php

namespace Drupal\medium_importer\Drush\Commands;

use Drush\Commands\DrushCommands;
use Drupal\Core\File\FileSystemInterface;
use Drupal\file\Entity\File;
use Drupal\media\Entity\Media;
use Drupal\menu_link_content\Entity\MenuLinkContent;
use Drupal\node\Entity\Node;
use GuzzleHttp\ClientInterface;

class MediumImportCommands extends DrushCommands {

  /**
   * The http client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  public function __construct(ClientInterface $http_client = NULL) {
    parent::__construct();
    $this->httpClient = $http_client ?: \Drupal::httpClient();
  }

  /**
   * Import Medium HTML files from a directory.
   *
   * @command medium_importer:import
   * @aliases medium-import
   * @param string $dir
   *   Absolute path to directory with .html files exported from Medium.
   * @option uid The user ID to own created nodes/media/files. Defaults to 1.
   * @usage medium_importer:import /var/www/html/medium-export
   *   Import all .html files found in that directory.
   */
  public function import($dir, $options = ['uid' => 1]) {

    $uid = isset($options['uid']) ? (int) $options['uid'] : 1;

    if (!is_dir($dir)) {
      $this->logger()->error("Directory not found: {$dir}");
      return 1;
    }

    $files = glob(rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '*.html');
    if (empty($files)) {
      $this->logger()->notice("No .html files found in {$dir}");
      return 0;
    }

    $total_nodes = 0;
    $total_images = 0;

    foreach ($files as $file_path) {
      $this->io()->title("Importing: " . basename($file_path));

      // Per-file counters.
      $file_images = 0;
      $file_nodes = 0;

      // Use Drupal's file_system service for path resolution.
      $fs = \Drupal::service('file_system');
      $realpath = $fs->realpath($file_path);
      $html = file_get_contents($realpath);
      if ($html === FALSE) {
        $this->logger()->warning("Failed to read file: {$file_path}");
        continue;
      }

      // Get created date from the file name.
      $created_date = explode('_', basename($file_path))[0];

      libxml_use_internal_errors(true);
      $doc = new \DOMDocument();
      $doc->loadHTML('<?xml encoding="utf-8" ?>' . $html);
      libxml_clear_errors();

      $xpath = new \DOMXPath($doc);

      // Get subtitle text (if present) for the summary.
      $subtitle_nodes = $xpath->query('//section[@data-field="subtitle" and contains(@class, "p-summary")]');
      $summary_text = '';
      if ($subtitle_nodes->length) {
        $summary_text = trim($subtitle_nodes->item(0)->textContent);
      }

      // Get the title.
      $title_nodes = $xpath->query('//h1[contains(@class, "p-name")]');
      $title = $title_nodes->length ? trim($title_nodes->item(0)->textContent) : basename($file_path);

      // Remove the first <hr> in the body, if any, before the body is built.
      $hr_nodes = $xpath->query('//section[@data-field="body"]//hr');
      if ($hr_nodes->length > 0) {
        $first_hr = $hr_nodes->item(0);
        if ($first_hr->parentNode) {
          $first_hr->parentNode->removeChild($first_hr);
        }
      }

      // Remove any <h3> elements with graf--title class before the body is built.
      $h3_nodes = $xpath->query('//section[@data-field="body"]//h3[contains(@class, "graf--title")]');
      foreach ($h3_nodes as $h3) {
        if ($h3->parentNode) {
          $h3->parentNode->removeChild($h3);
        }
      }

      $body_nodes = $xpath->query('//section[@data-field="body"]');
      if ($body_nodes->length === 0) {
        $this->logger()->warning("No body section found in {$file_path}, skipping.");
        continue;
      }
      $body_node = $body_nodes->item(0);

      // Downgrade headings to paragraphs.
      foreach (['h2','h3','h4','h5','h6'] as $tag) {
        $nodes = $xpath->query('.//' . $tag, $body_node);
        foreach ($nodes as $node) {
          $p = $doc->createElement('p');
          foreach (iterator_to_array($node->childNodes) as $child) {
            $p->appendChild($child->cloneNode(true));
          }
          if ($node->parentNode) {
            $node->parentNode->replaceChild($p, $node);
          }
        }
      }

      // Find any <blockquote> in the body that contains the phrase "To stay up to date".
      $blockquote_nodes = $xpath->query('.//blockquote[contains(., "To stay up to date")]', $body_node);
      foreach ($blockquote_nodes as $bq) {
        if ($bq->parentNode) {
          $bq->parentNode->removeChild($bq);
        }
      }

      $img_nodes = [];
      $img_xpath_result = $xpath->query('.//img', $body_node);
      foreach ($img_xpath_result as $img) {
        $img_nodes[] = $img;
      }

      $media_entities = [];
      $is_first = TRUE;

      foreach ($img_nodes as $index => $img) {
        $src = $img->getAttribute('src');
        if (empty($src)) {
          $src = $img->getAttribute('data-src');
        }
        if (empty($src)) {
          $this->logger()->warning("Image without src encountered in {$file_path}, skipping image #{$index}");
          continue;
        }

        // Change to a larger size image.
        $src = preg_replace('#/max/(\d+)/#', '/max/2000/', $src);

        $this->logger()->notice("Downloading image: {$src}");

        try {
          $response = $this->httpClient->request('GET', $src);
          if ($response->getStatusCode() !== 200) {
            $this->logger()->warning("Failed to download image: {$src}");
            continue;
          }
          $data = (string) $response->getBody();
        }
        catch (\Exception $e) {
          $this->logger()->warning("HTTP error downloading image: " . $e->getMessage());
          continue;
        }

        $path_parts = pathinfo(parse_url($src, PHP_URL_PATH));
        $basename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $path_parts['basename']);
        $directory = 'public://medium_importer';
        $destination = $directory . $basename;

        // Ensure directory exists with FileSystem service.
        $fs->prepareDirectory(
          $directory,
          FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS
        );

        // Save file to filesystem.
        $uri = $fs->saveData($data, $destination, FileSystemInterface::EXISTS_RENAME);

        if (!$uri) {
          $this->logger()->warning("Failed to save image to {$destination}");
          continue;
        }

        // Create File entity.
        $file = File::create([
          'uri' => $uri,
          'uid' => $uid,
          'status' => 1,
        ]);
        $file->save();

        try {
          $media = Media::create([
            'bundle' => 'image',
            'uid' => $uid,
            'name' => $basename,
            'field_media_image' => [
              'target_id' => $file->id(),
              'alt' => $img->getAttribute('alt') ?: ' ',
            ],
            'status' => 1,
          ]);
          $media->save();
        }
        catch (\Exception $e) {
          $this->logger()->warning("Failed to create media entity: " . $e->getMessage());
          continue;
        }

        $media_entities[] = $media;
        $file_images++;
        $total_images++;

        if ($is_first) {
          $figure = $img->parentNode;
          if ($figure && ($figure->nodeName === 'figure' || $figure->getAttribute('class') !== '')) {
            $figure->parentNode->removeChild($figure);
          }
          else {
            if ($img->parentNode) {
              $img->parentNode->removeChild($img);
            }
          }

          $is_first = FALSE;
          continue;
        }

        $media_uuid = $media->uuid();
        $caption_text = '';

        // Check if the image is inside a <figure> that has a <figcaption>.
        $figure_node = $img->parentNode;
        if ($figure_node && $figure_node->nodeName === 'figure') {
          $caption_nodes = $figure_node->getElementsByTagName('figcaption');
          if ($caption_nodes->length > 0) {
            $caption_text = trim($caption_nodes->item(0)->textContent);
            // Remove the <figcaption> so it doesn't appear in the body.
            foreach ($caption_nodes as $caption_node) {
              if ($caption_node->parentNode) {
                $caption_node->parentNode->removeChild($caption_node);
              }
            }
          }
        }

        if ($caption_text) {
          $drupal_media_html = "<drupal-media data-entity-type=\"media\" data-align=\"center\" data-view-mode=\"wide\" data-entity-uuid=\"{$media_uuid}\" data-caption=\"" . htmlspecialchars($caption_text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "\"></drupal-media>";
        }
        else {
          $drupal_media_html = "<drupal-media data-entity-type=\"media\" data-align=\"center\" data-view-mode=\"wide\" data-entity-uuid=\"{$media_uuid}\"></drupal-media>";
        }

        $fragment = $doc->createDocumentFragment();
        $fragment->appendXML($drupal_media_html);
        $img->parentNode->replaceChild($fragment, $img);
      }

      $body_html = '';
      foreach ($body_node->childNodes as $child) {
        $body_html .= $doc->saveHTML($child);
      }

      try {
        $node_values = [
          'type' => 'featured_content',
          'title' => $title,
          'uid' => $uid,
          'created' => strtotime($created_date),
          'changed' => strtotime($created_date),
          'status' => 1,
          'body' => [
            'value' => $body_html,
            'format' => 'standard',
            'summary' => $summary_text,
          ],
        ];

        if (!empty($media_entities)) {
          $lead_media = $media_entities[0];
          $node_values['featured_content_lead_media'] = [
            'target_id' => $lead_media->id(),
            'target_type' => 'media',
          ];
        }

        $node = Node::create($node_values);
        $node->save();
        $file_nodes++;
        $total_nodes++;

        $this->logger()->success("Created node {$node->id()} for {$title}");
      }
      catch (\Exception $e) {
        $this->logger()->error("Failed to create node for {$file_path}: " . $e->getMessage());
        continue;
      }

      // Log per-file totals.
      $this->logger()->notice("Imported {$file_nodes} node(s) with {$file_images} image(s) from " . basename($file_path));
    }

    $this->io()->success("Import complete. Created {$total_nodes} nodes and {$total_images} images in total.");
    return 0;
  }

}

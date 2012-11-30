<?php

/**
 * Import Wordpress content. Currently only imports blog posts (with any inline images present in the posts).
 * Drafts are not imported. Authors are imported, and you can specify an author mapping file with --authors.
 * Disqus comments will be retained if you specify --disqus and your existing Wordpress blog is
 * using the standard Disqus wordpress plugin, with disqus identifiers in its standard format
 */

class aImportWordpressTask extends sfBaseTask
{
  protected function configure()
  {
    $this->addOptions(array(
      new sfCommandOption('env', null, sfCommandOption::PARAMETER_REQUIRED, 'The environment', 'dev'),
      new sfCommandOption('connection', null, sfCommandOption::PARAMETER_REQUIRED, 'The connection name', 'doctrine'),
      new sfCommandOption('xml', null, sfCommandOption::PARAMETER_REQUIRED, 'An XML file created by the Wordpress export feature', null),
      new sfCommandOption('authors', null, sfCommandOption::PARAMETER_REQUIRED, 'An author mapping XML file (see the blog-import task)', null),
      new sfCommandOption('clear', null, sfCommandOption::PARAMETER_NONE, 'Remove existing posts and/or events', null),
      new sfCommandOption('ignore-empty-title', null, sfCommandOption::PARAMETER_NONE, 'Ignore all posts and events with empty titles', null),
      new sfCommandOption('disqus', null, sfCommandOption::PARAMETER_NONE, 'Import existing Disqus threads', null),
      new sfCommandOption('defaultUsername', null, sfCommandOption::PARAMETER_REQUIRED, 'Default author of posts', 'admin'),
      new sfCommandOption('category', null, sfCommandOption::PARAMETER_REQUIRED, 'Category to apply to ALL imported posts', 'admin'),
      new sfCommandOption('categories-as-tags', null, sfCommandOption::PARAMETER_NONE, 'All categories found in the import are treated as tags', null),
      new sfCommandOption('tag-to-entity', null, sfCommandOption::PARAMETER_NONE, 'Convert tags to entity relationships if an entity by that name exists (applied after categories-as-tags)', null),
      new sfCommandOption('skip-confirmation', null, sfCommandOption::PARAMETER_NONE, 'Skip confirmation prompt', null)
      // add your own options here
    ));

    $this->namespace = 'apostrophe';
    $this->name = 'import-wordpress';
    $this->briefDescription = 'Imports a blog from a Wordpress XML export';
    $this->detailedDescription = <<<EOF
Usage:

php symfony apostrophe:import-wordpress --xml=wordpress-export-file.xml [--disqus]
EOF;
  }

  protected function execute($args = array(), $options = array())
  {
    $databaseManager = new sfDatabaseManager($this->configuration);
    $connection = $databaseManager->getDatabase($options['connection'])->getDoctrineConnection();
    if (is_null($options['xml']))
    {
      echo("Required option --xml=filename not given. Generate a Wordpress export XML file first.\n");
      exit(1);
    }
    $xml = simplexml_load_file($options['xml']);
    if (!$xml)
    {
      echo("Unable to open or parse XML file\n");
      exit(0);
    }
    $channel = $xml->channel[0];
    $out = <<<EOM
<?xml version="1.0" encoding="UTF-8"?>
<posts>
EOM
;
    $statusWarn = false;
    foreach ($xml->channel[0]->item as $item)
    {
      $tags = array();
      $categories = array();
      if ($options['category'])
      {
        $categories[] = $options['category'];
      }
      $dcXml = $item->children('http://purl.org/dc/elements/1.1/');
      $wpXml = $item->children('http://wordpress.org/export/1.0/');
      if (!count($wpXml))
      {
        // newer WP
        $wpXml = $item->children('http://wordpress.org/export/1.1/');
      }
      if (!count($wpXml))
      {
        // newer WP
        $wpXml = $item->children('http://wordpress.org/export/1.2/');
      }
      // Skip photo attachments, pages, anything else that isn't a post 
      // (we do pull the photos actually appearing in posts in via apostrophe's import mechanisms)
      if (((string) $wpXml->post_type) !== 'post')
      {
        continue;
      }
      
      if (((int) $wpXml->post_parent) > 0)
      {
        // Just the blog post proper, these never seem to be useful
        // (we'll feel differently when we get to importing pages)
        continue;
      }
      $title = $this->escape($item->title[0]);
      if ($options['ignore-empty-title'] && (!strlen($title)))
      {
        echo("Ignoring post with empty title\n");
        continue;
      }
      // In our exports pubDate was always wrong (the same value for every item)
      // so post_date was a much more reasonable value
      $published_at = $this->escape($wpXml->post_date[0]);
      $slug = $this->escape($wpXml->post_name[0]);
      $status = $this->escape($wpXml->status[0]);
      $link = $this->escape($item->link[0]);
      $author = $this->escape($dcXml->creator[0]);
      $contentXml = $item->children('http://purl.org/rss/1.0/modules/content/');
      $body = $this->escape($contentXml->encoded[0]);
      // Blank lines = paragraph breaks in Wordpress. This is difficult to translate
      // to Apostrophe cleanly because it's nontrivial to turn them into nice
      // paragraph containers. Go with a double br to get the same effect for now
      $body = preg_replace('/(\r)?\n(\r)?\n/', "\r\n&lt;br /&gt;&lt;br /&gt;\r\n", $body);
      if ($status === 'draft')
      {
        if (!$statusWarn)
        {
          echo("WARNING: unpublished drafts are not imported\n");
          $statusWarn = true;
        }
        continue;
      }

      if (isset($wpXml->postmeta)) 
      {
        foreach ($wpXml->postmeta as $postmeta)
        {
          $key = (string) $postmeta->meta_key;
          $value = (string) $postmeta->meta_value;
          if ($key === '_wp_geo_latitude')
          {
            $latitude = $value;
          } elseif ($key === '_wp_geo_longitude')
          {
            $longitude = $value;
          }
        }
      }

      if (isset($latitude) && isset($longitude))
      {
        $location = $this->escape("$latitude, $longitude");
      }

      foreach ($item->category as $category)
      {
        if ($options['categories-as-tags'])
        {
          $tags[] = (string) $category;
        }
        else
        {
          $domain = (string) $category['domain'];
          if ($domain === 'tag')
          {
            $tags[] = (string) $category;
          }
          elseif ($domain === 'category')
          {
            $categories[] = (string) $category;
          }
        }
      }
      // Look for a disqus thread using the standard Wordpress Disqus plugin's
      // format for thread identifiers
      $disqus_thread_identifier_attribute = '';
      if ($options['disqus'])
      {
        $postId = (string) $wpXml->post_id;
        $guid = (string) $item->guid;
        $disqus_thread_identifier_attribute = "disqus_thread_identifier=\"" . $this->escape("$postId $guid") . "\"";
      }
      $out .= <<<EOM
  <post $disqus_thread_identifier_attribute published_at="$published_at" slug="$slug">
    <title>$title</title>
    <author>$author</author>

EOM
;
      if (isset($location))
      {
        $out .= <<<EOM
    <location>$location</location>

EOM
;
      }
      $out .= <<<EOM
    <categories>
    
EOM
;
      // Since WP category names are CDATA containing already-escaped entities,
      // don't double-escape them
      foreach ($categories as $category)
      {
        $out .= "      <category>" . $category . "</category>\n";
      }
      $out .= <<<EOM
    </categories>
    <tags>
    
EOM
;
      // Since WP category names are CDATA containing already-escaped entities,
      // don't double-escape them
      foreach ($tags as $tag)
      {
        $out .= "      <tag>" . $tag . "</tag>\n";
      }
      $out .= <<<EOM
    </tags>
    <Page>
      <Area name="blog-body">
        <Slot type="foreignHtml">
          <value>$body</value>
        </Slot>
      </Area>
    </Page>
  </post>
EOM
;
    }
    $out .= <<<EOM
</posts>
EOM
;
    $ourXml = aFiles::getTemporaryFilename();
    file_put_contents($ourXml, $out);
    $task = new aBlogImportTask($this->dispatcher, $this->formatter);
    $boptions = array('posts' => $ourXml, 'env' => $options['env'], 'connection' => $options['connection'], 'clear' => $options['clear'], 'tag-to-entity' => $options['tag-to-entity'], 'skip-confirmation' => $options['skip-confirmation']);
    if (isset($options['authors'])) 
    {
      $boptions['authors'] = $options['authors'];
    }
    if (isset($options['defaultUsername']))
    {
      $boptions['defaultUsername'] = $options['defaultUsername'];
    }
    $task->run(array(), $boptions);
    aFiles::unlink($ourXml);
  }
  
  public function escape($s)
  {
    // Yes, we really mean it if we double-encode here
    return htmlspecialchars((string) $s, ENT_COMPAT, 'UTF-8', true);
  }
}

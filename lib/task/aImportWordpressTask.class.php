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
      new sfCommandOption('disqus', null, sfCommandOption::PARAMETER_NONE, 'Import existing Disqus threads', null)
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
      $dcXml = $item->children('http://purl.org/dc/elements/1.1/');
      $wpXml = $item->children('http://wordpress.org/export/1.0/');
      if (!count($wpXml))
      {
        // newer WP
        $wpXml = $item->children('http://wordpress.org/export/1.1/');
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
      foreach ($item->category as $category)
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
    <categories>
    
EOM
;
      foreach ($categories as $category)
      {
        $out .= "      <category>" . $this->escape($category) . "</category>\n";
      }
      $out .= <<<EOM
    </categories>
    <tags>
    
EOM
;
      foreach ($tags as $tag)
      {
        $out .= "      <tag>" . $this->escape($tag) . "</tag>\n";
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
    $boptions = array('posts' => $ourXml, 'env' => $options['env'], 'connection' => $options['connection']);
    if (isset($options['authors']))
    {
      $boptions['authors'] = $options['authors'];
    }
    $task->run(array(), $boptions);
    aFiles::unlink($ourXml);
  }
  
  public function escape($s)
  {
    // Yes, we really mean it when we double-encode here
    return htmlspecialchars((string) $s, ENT_COMPAT, 'UTF-8', true);
  }
}
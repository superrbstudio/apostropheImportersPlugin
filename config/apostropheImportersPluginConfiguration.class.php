<?php

/**
 * apostropheImportersPlugin configuration.
 * 
 * @package     apostropheImportersPlugin
 * @subpackage  config
 * @author      Your name here
 * @version     SVN: $Id: PluginConfiguration.class.php 17207 2009-04-10 15:36:26Z Kris.Wallsmith $
 */
class apostropheImportersPluginConfiguration extends sfPluginConfiguration
{

  static $registered = false;
  /**
   * @see sfPluginConfiguration
   */
  public function initialize()
  {
    // Yes, this can get called twice. This is Fabien's workaround:
    // http://trac.symfony-project.org/ticket/8026
    
    if (!self::$registered)
    {
      $this->dispatcher->connect('command.post_command', array($this, 'listenToCommandPostCommandEvent'));
      
      self::$registered = true;
    }
  }

  // command.post_command
  public function listenToCommandPostCommandEvent(sfEvent $event)
  {
    $task = $event->getSubject();

    if ($task->getFullName() === 'apostrophe:migrate')
    {
      $this->migrate();
    }
  }

  public function migrate()
  {
    $migrate = new aMigrate(Doctrine_Manager::connection()->getDbh());
    if (!$migrate->columnExists('a_blog_item', 'disqus_thread_identifier'))
    {
      $migrate->sql(array('alter table a_blog_item add column disqus_thread_identifier varchar(100)'));
    }
  }
}

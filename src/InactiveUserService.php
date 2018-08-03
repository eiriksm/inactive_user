<?php

namespace Drupal\inactive_user;

use Drupal\Core\Database\Driver\mysql\Connection;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * Class InactiveUserService.
 */
class InactiveUserService implements InactiveUserServiceInterface {

  /**
   * Drupal\Core\Database\Driver\mysql\Connection definition.
   *
   * @var \Drupal\Core\Database\Driver\mysql\Connection
   */
  protected $database;

  /**
   * Drupal\Core\Config\ConfigFactoryInterface definition.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Drupal\Core\Datetime\DateFormatterInterface definition.
   *
   * @var Drupal\Core\Datetime\DateFormatterInterface
   */
  protected $dateFormatter;

  /**
   * Drupal\Core\Logger\LoggerChannelFactoryInterface definition.
   *
   * @var Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;

  /**
   * Inactive user config.
   */
  protected $config;

  /**
   * The system config variable.
   */
  protected $siteName;

  /**
   * {@inheritdoc}
   */
  public function __construct(
  Connection $database, ConfigFactoryInterface $config_factory, DateFormatterInterface $date_formatter, LoggerChannelFactoryInterface $logger_factory) {
    $this->database = $database;
    $this->configFactory = $config_factory;
    $this->dateFormatter = $date_formatter;
    $this->loggerFactory = $logger_factory;
    $this->config = $this->configFactory->getEditable('inactive_user.inactiveuseradmin');
    $this->siteName = $this->getSiteName();
  }

  /**
   * {@inheritdoc}
   */
  public function runCron() {
    if ((REQUEST_TIME - $this->config->get('inactive_user_timestamp')) >= DAY_MINUS_FIVE_MINUTES) {
      $this->config->set('inactive_user_timestamp', REQUEST_TIME);

      $this->resetAdminNotifications();
      $this->notifyAdmin();
      $this->notifyUser();
      $this->warnedUserBlockTimestamp();
      $this->notifyUserBlock();
      $this->warnedUserDeleteTimestamp();
      $this->autoUserDelete();
    }
  }

  /**
   * {@inheritdoc}
   */
  public function resetAdminNotifications() {
    // Reset notifications if recent user activity.
    $query = $this->database->select('users_field_data', 'u');
    $query->fields('u', ['uid', 'name']);
    $query->condition('u.uid', 1, '<>');
    $query->condition('u.notified_admin', 1);
    $query->condition('u.access', REQUEST_TIME - ONE_WEEK, '>');
    $result = $query->execute()->fetchAllAssoc('uid');
    if (count($result) > 0) {
      foreach ($result as $record) {
        $this->loggerFactory->get('user')->notice('recent user activity: %user removed from inactivity list', ['%user' => $record->name]);
      }
      $query = $this->database->update('users_field_data');
      $query->fields(['notified_admin' => 0]);
      $query->condition('uid', array_keys($result), 'in');
      $query->execute();
    }
  }

  /**
   * {@inheritdoc}
   */
  public function notifyAdmin() {
    // Notify administrator of inactive user accounts.
    if ($notify_time = $this->config->get('inactive_user_notify_admin')) {
      $query = $this->database->select('users_field_data', 'u');
      $query->fields('u', array('uid', 'name', 'mail', 'access', 'created'));
      $and_condition1 = $query->andConditionGroup()
        ->condition('u.access', 0, '<>')
        ->condition('u.login', 0, '<>')
        ->condition('u.access', REQUEST_TIME - $notify_time, '<');
      $and_condition2 = $query->andConditionGroup()
        ->condition('u.login', 0)
        ->condition('u.created', REQUEST_TIME - $notify_time, '<');
      $query->orConditionGroup()
        ->condition($and_condition1)
        ->condition($and_condition2);
      // Has not the admin been notified.
      $query->condition('u.notified_admin', 0);
      $query->condition('u.uid', 1, '<>');

      // Adds queryTag to identify this query in a custom module using the hook_query_TAG_alter().
      // The first tag is a general identifier so you can include all the queries that are being processed in this hook_cron().
      // The second tag is unique and only used to make changes to this particular query.
      $query->addTag('inactive_user');
      $query->addTag('notified_admin');

      $results = $query->execute();

      foreach ($results as $user) {
        $uids = [];
        if ($user->uid && ($user->access < (REQUEST_TIME - $notify_time))) {
          $uids[] = $user->uid;
          $user_list .= "$user->name ($user->mail) last active on " . $this->dateFormatter->format($user->access, 'large') . ".\n";
        }
      }
      if (!empty($uids)) {
        // Update queries return rows updated.
        $query = $this->database->update('users_field_data');
        $query->fields(['notified_admin' => 1]);
        $query->condition('uid', $uids, 'in');
        $query->execute();

        $this->mail(t('[@sitename] Inactive users', ['@sitename' => $this->siteName]), $this->getMailText('notify_admin_text'), $notify_time, NULL, $user_list);
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function notifyUser() {
    // Notify users that their account has been inactive.
    if ($notify_time = $this->config->get('inactive_user_notify')) {
      $query = $this->database->select('users_field_data', 'u');
      $query->fields('u', array('uid', 'name', 'mail', 'access', 'created'));
      $and_condition1 = $query->andConditionGroup()
        ->condition('u.access', 0, '<>')
        ->condition('u.login', 0, '<>')
        ->condition('u.access', REQUEST_TIME - $notify_time, '<');
      $and_condition2 = $query->andConditionGroup()
        ->condition('u.login', 0)
        ->condition('u.created', REQUEST_TIME - $notify_time, '<');
      $query->orConditionGroup()
        ->condition($and_condition1)
        ->condition($and_condition2);
      // Has not the admin been notified.
      $query->condition('u.notified_user', 0);
      $query->condition('u.uid', 1, '<>');

      // Adds queryTag to identify this query in a custom module using the hook_query_TAG_alter().
      // The first tag is a general identifier so you can include all the queries that are being processed in this hook_cron().
      // The second tag is unique and only used to make changes to this particular query.
      $query->addTag('inactive_user');
      $query->addTag('notified_user');

      $results = $query->execute();

      $mail_text = $this->getMailText('inactive_user_notify_text');
      foreach ($results as $user) {
        $uids = [];
        if ($user->uid && ($user->access < (REQUEST_TIME - $notify_time))) {
          $uids[] = $user->uid;
          $this->mail(t('[@sitename] Account inactivity', ['@sitename' => $this->siteName]), $mail_text, $notify_time, $user, NULL);
          $this->loggerFactory->get('user')->notice('user %user notified of inactivity', array('%user' => $user->name));
        }
      }
      if (!empty($uids)) {
        // Update queries return rows updated.
        $query = $this->database->update('users_field_data');
        $query->fields(['notified_user' => 1]);
        $query->condition('uid', $uids, 'in');
        $query->execute();
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function warnedUserBlockTimestamp() {
    if (($warn_time = $this->config->get('inactive_user_auto_block_warn')) &&
      ($block_time = $this->config->get('inactive_user_auto_block'))) {
      $query = $this->database->select('users_field_data', 'u');
      $query->fields('u', array('uid', 'name', 'mail', 'created', 'access'));
      $and_condition1 = $query->andConditionGroup()
        ->condition('u.access', 0, '<>')
        ->condition('u.login', 0, '<>')
        ->condition('u.access', REQUEST_TIME - $block_time + $warn_time, '<');
      $and_condition2 = $query->andConditionGroup()
        ->condition('u.login', 0)
        ->condition('u.created', REQUEST_TIME - $block_time + $warn_time, '<');
      $query->orConditionGroup()
        ->condition($and_condition1)
        ->condition($and_condition2);
      $query->condition('u.warned_user_block_timestamp', 0, '>');
      $query->condition('u.status', 0, '<>');
      $query->condition('u.uid', 1, '<>');

      // Adds queryTag to identify this query in a custom module using the hook_query_TAG_alter().
      // The first tag is a general identifier so you can include all the queries that are being processed in this hook_cron().
      // The second tag is unique and only used to make changes to this particular query.
      $query->addTag('inactive_user');
      $query->addTag('warned_user_block_timestamp');

      $results = $query->execute();

      $uids = [];
      $mail_text = $this->getMailText('inactive_user_block_warn_text');
      foreach ($results as $user) {
        $uids[] = $user->id();
        $this->mail(t('[@sitename] Account inactivity', ['@sitename' => $this->siteName]), $mail_text, $warn_time, $user, NULL);
        $this->loggerFactory->get('user')->notice('user %user warned will be blocked due to inactivity', ['%user' => $user->name]);
      }
    }
    if (!empty($uids)) {
      // Update queries return rows updated.
      $query = $this->database->update('users_field_data');
      $query->fields(['warned_user_block_timestamp' => REQUEST_TIME + $warn_time]);
      $query->condition('uid', $uids, 'in');
      $query->execute();
    }
  }

  /**
   * {@inheritdoc}
   */
  public function notifyUserBlock() {
    // TODO: check again to original code functionality.
    // Automatically block users.
    if ($block_time = $this->config->get('inactive_user_auto_block')) {
      $query = $this->database->select('users_field_data', 'u');
      $query->fields('u', array('uid', 'name', 'mail', 'created', 'access, warned_user_block_timestamp, notified_admin_block'));
      $and_condition1 = $query->andConditionGroup()
        ->condition('u.access', 0, '<>')
        ->condition('u.login', 0, '<>')
        ->condition('u.access', REQUEST_TIME - $block_time, '<');
      $and_condition2 = $query->andConditionGroup()
        ->condition('u.login', 0)
        ->condition('u.created', REQUEST_TIME - $block_time, '<');
      $query->orConditionGroup()
        ->condition($and_condition1)
        ->condition($and_condition2);
      $query->condition('u.notified_user_block', 0);
      $query->condition('u.status', 0, '<>');
      $query->condition('u.uid', 1, '<>');

      // Adds queryTag to identify this query in a custom module using the hook_query_TAG_alter().
      // The first tag is a general identifier so you can include all the queries that are being processed in this hook_cron().
      // The second tag is unique and only used to make changes to this particular query.
      $query->addTag('inactive_user');
      $query->addTag('warned_user_block_timestamp');

      $results = $query->execute();

      $inactive_uids = [];
      $notified_uids = [];
      $notify_admin_uids = [];
      $mail_text_user = $this->getMailText('inactive_user_block_notify_text');
      $mail_text_admin = $this->getMailText('block_notify_admin_text');

      foreach ($results as $user) {
        // Don't block user yet if we sent a warning and it hasn't expired.
        if ($user->uid &&
          $user->warned_user_block_timestamp > REQUEST_TIME &&
          ($user->access < (REQUEST_TIME - $block_time))) {

          $inactive_uids[] = $user->id();

          // Notify user.
          if ($this->config->get('inactive_user_notify_block')) {
            $notified_uids[] = $user->uid;
            $this->mail(t('[@sitename] Account blocked due to inactivity', ['@sitename' => $this->siteName]), $mail_text_user, $block_time, $user, NULL);
            $this->loggerFactory->get('user')->notice('user %user blocked due to inactivity', ['%user' => $user->name]);
          }

          // Notify admin.
          if ($this->config->get('inactive_user_notify_block_admin')) {
            if (empty($user->notified_admin_block)) {
              $notify_admin_uids[] = $user->uid;
              $user_list .= "$user->name ($user->mail) last active on " . $this->dateFormatter->format($user->access, 'large') . ".\n";
            }
          }
        }
        if (!empty($user_list)) {
          $this->mail(t('[@sitename] Blocked users', ['@sitename' => $this->siteName]), $mail_text_admin, $block_time, NULL, $user_list);
        }
      }
      $query = $this->database->update('users_field_data')
        ->fields(['status' => 0])
        ->condition('uid', $inactive_uids, 'in')
        ->execute();
      $query = $this->database->update('users_field_data')
        ->fields(['notified_user_block' => 1])
        ->condition('uid', $notified_uids, 'in')
        ->execute();
      $query = $this->database->update('users_field_data')
        ->fields(['notified_admin_block' => 1])
        ->condition('uid', $notify_admin_uids, 'in')
        ->execute();
    }
  }

  /**
   * {@inheritdoc}
   */
  public function warnedUserDeleteTimestamp() {
    // Warn users when they are about to be deleted.
    if (($warn_time = $this->config->get('inactive_user_auto_delete_warn')) &&
      ($delete_time = $this->config->get('inactive_user_auto_delete'))) {
      $query = db_select('users_field_data', 'u');
      $query->fields('u', array('uid', 'name', 'mail', 'created', 'access', 'warned_user_delete_timestamp'));
      $and_condition1 = $query->andConditionGroup()
        ->condition('u.access', 0, '<>')
        ->condition('u.login', 0, '<>')
        ->condition('u.access', REQUEST_TIME - $delete_time + $warn_time, '<');
      $and_condition2 = $query->andConditionGroup()
        ->condition('u.login', 0)
        ->condition('u.created', REQUEST_TIME - $delete_time + $warn_time, '<');
      $query->orConditionGroup()
        ->condition($and_condition1)
        ->condition($and_condition2);
      $query->condition('u.uid', 1, '<>');

      // Adds queryTag to identify this query in a custom module using the hook_query_TAG_alter().
      // The first tag is a general identifier so you can include all the queries that are being processed in this hook_cron().
      // The second tag is unique and only used to make changes to this particular query.
      $query->addTag('inactive_user');
      $query->addTag('warn_users_deleted');

      $results = $query->execute();

      $mail_text = $this->getMailText('inactive_user_delete_warn_text');
      foreach ($results as $user) {
        if (empty($user->warned_user_delete_timestamp) &&
          ($user->access < (REQUEST_TIME - $warn_time))) {
          $protected = ($this->config->get('inactive_user_preserve_content') && $this->inactiveUserWithContent($user->uid));

          // The db_update function returns the number of rows altered.
          $query = $this->database->update('users_field_data')
            ->fields([
              'warned_user_delete_timestamp' => REQUEST_TIME + $warn_time,
              'protected' => $protected,
            ])
            ->condition('uid', $user->uid)
            ->execute();

          if (!$protected) {
            $this->mail(t('[@sitename] Account inactivity', ['@sitename' => $this->siteName]), $mail_text, $warn_time, $user, NULL);
            $this->loggerFactory->get('user')->notice('user %user warned will be deleted due to inactivity', ['%user' => $user->mail]);
          }
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function autoUserDelete() {
    // Automatically delete users.
    if ($delete_time = $this->config->get('inactive_user_auto_delete')) {
      $query = $this->database->select('users_field_data', 'u');
      $query->fields('u', array('uid', 'name', 'mail', 'created', 'access', 'warned_user_delete_timestamp', 'protected'));
      $and_condition1 = $query->andConditionGroup()
        ->condition('u.access', 0, '<>')
        ->condition('u.login', 0, '<>')
        ->condition('u.access', REQUEST_TIME - $delete_time, '<');
      $and_condition2 = $query->andConditionGroup()
        ->condition('u.login', 0)
        ->condition('u.created', REQUEST_TIME - $delete_time, '<');
      $query->orConditionGroup()
        ->condition($and_condition1)
        ->condition($and_condition2);
      $query->condition('u.uid', 1, '<>');

      // Adds queryTag to identify this query in a custom module using the hook_query_TAG_alter().
      // The first tag is a general identifier so you can include all the queries that are being processed in this hook_cron().
      // The second tag is unique and only used to make changes to this particular query.
      $query->addTag('inactive_user');
      $query->addTag('delete_users');

      $results = $query->execute();

      $mail_text = $this->getMailText('inactive_user_delete_notify_text');
      foreach ($results as $user) {
        $deleteable_user_results = ($user->warned_user_delete_timestamp < REQUEST_TIME && $user->protected != 1);
        if ($user->uid &&
          ((($this->config->get('inactive_user_auto_delete_warn') > 0) && !$deleteable_user_results) ||
          (!$this->config->get('inactive_user_auto_delete_warn'))) && ($user->access < (REQUEST_TIME - $delete_time))) {

          $is_protected = (variable_get('inactive_user_preserve_content', 1) && $this->inactiveUserWithContent($user->uid));
          if ($is_protected) {
            // This is a protected user, mark as such.
            $query = db_update('users_field_data')
              ->fields(['protected' => $is_protected])
              ->condition('uid', $user->uid)
              ->execute();
          }
          else {
            // Delete the user.
            // Not using user_delete() to send custom emails and watchdog.
            // $array = (array) $user;

            // TODO: look into which methode using for User entity deletion.
            drupal_session_destroy_uid($user->uid);
            \Drupal\user\Entity\User::delete($user->uid);

            /*
            db_delete('users')
              ->condition('uid', $user->uid)
              ->execute();
            db_delete('users_field_data')
              ->condition('uid', $user->uid)
              ->execute();
            db_delete('user__roles')
              ->condition('uid', $user->uid)
              ->execute();
            db_delete('inactive_users')
              ->condition('uid', $user->uid)
              ->execute();
            module_invoke_all('user', 'delete', $array, $user);
             * 
             */
            if ($this->config->get('inactive_user_notify_delete')) {
              $this->mail(t('[@sitename] Account removed', ['@sitename' => $this->siteName]), $mail_text, $delete_time, $user, NULL);
            }
            if ($this->config->get('inactive_user_notify_delete_admin')) {
              $user_list .= "$user->name ($user->mail) last active on " . $this->dateFormatter->format($user->access, 'large') . ".\n";
            }
            $this->loggerFactory->get('user')->notice('user %user deleted due to inactivity', ['%user' => $user->name]);
          }
        }
      }
      if (!empty($user_list)) {
        $this->mail(t('[@sitename] Deleted accounts', ['@sitename' => $this->siteName]), $this->getMailText('delete_notify_admin_text'), $delete_time, NULL, $user_list);
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function inactiveUserAdminMail() {
    $config = \Drupal::config('inactive_user.inactiveuseradmin');
    if ($adresses = $this->config->get('inactive_user_admin_email')) {
      return $adresses;
    }
    $admin = \Drupal\user\Entity\User::load(1);
    return $admin->getEmail();
  }

  /**
   * {@inheritdoc}
   */
  public function getMailText($key) {
    $mail_text = $this->mailText($key);
    if ($text = $this->config->get($key)) {
      $mail_text = $text;
    }

    return $mail_text;
  }

  /**
   * {@inheritdoc}
   */
  public function mailText($message) {
    switch ($message) {
      case 'notify_text':
        return t("Hello %username,\n\n  We haven't seen you at %sitename since %lastaccess, and we miss you!  Please come back and visit us soon at %siteurl.\n\nSincerely,\n  %sitename team");

      case 'notify_admin_text':
        return t("Hello,\n\n  This automatic notification is to inform you that the following users haven't been seen on %sitename for more than %period:\n\n%userlist");

      case 'block_warn_text':
        return t("Hello %username,\n\n  We haven't seen you at %sitename since %lastaccess, and we miss you!  This automatic message is to warn you that your account will be disabled in %period unless you come back and visit us before that time.\n\n  Please visit us at %siteurl.\n\nSincerely,\n  %sitename team");

      case 'block_notify_text':
        return t("Hello %username,\n\n  This automatic message is to notify you that your account on %sitename has been automatically disabled due to no activity for more than %period.\n\n  Please visit us at %siteurl to have your account re-enabled.\n\nSincerely,\n  %sitename team");

      case 'block_notify_admin_text':
        return t("Hello,\n\n  This automatic notification is to inform you that the following users have been automatically blocked due to inactivity on %sitename for more than %period:\n\n%userlist");

      case 'delete_warn_text':
        return t("Hello %username,\n\n  We haven't seen you at %sitename since %lastaccess, and we miss you!  This automatic message is to warn you that your account will be completely removed in %period unless you come back and visit us before that time.\n\n  Please visit us at %siteurl.\n\nSincerely,\n  %sitename team");

      case 'delete_notify_text':
        return t("Hello %username,\n\n  This automatic message is to notify you that your account on %sitename has been automatically removed due to no activity for more than %period.\n\n  Please visit us at %siteurl if you would like to create a new account.\n\nSincerely,\n  %sitename team");

      case 'delete_notify_admin_text':
        return t("Hello,\n\n  This automatic notification is to inform you that the following users have been automatically deleted due to inactivity on %sitename for more than %period:\n\n%userlist");
    }
  }

  /**
   * {@inheritdoc}
   */
  public function mail($subject, $message, $period, $user = NULL, $user_list = NULL) {
    $site_name = \Drupal::config('system.site')->get('name');
    if (empty($site_name)) {
      $site_name = 'Drupal';
    }

    $base_url = \Drupal::request()->getBaseUrl();
    $url = \Drupal\Core\Url::fromUserInput($base_url);
    $link = \Drupal\Core\Link::fromTextAndUrl($base_url, $url);

    $interval = \Drupal::service('date.formatter')->formatInterval($period);

    if ($user_list) {
      $to = $this->inactiveUserAdminMail();
      $variables = array(
        '%period' => $interval,
        '%sitename' => $site_name,
        '%siteurl' => $link->toString(),
        "%userlist" => $user_list,
      );
    }
    elseif (isset($user->uid)) {
      $to = $user->mail;
      $access = t('never');
      if (!empty($user->access)) {
        \Drupal::service('date.formatter')->format($user->access, 'short');
      }
      $variables = array(
        '%username' => $user->name,
        '%useremail' => $user->mail,
        '%lastaccess' => $access,
        '%period' => $interval,
        '%sitename' => $site_name,
        '%siteurl' => $link,
      );
    }
    if (isset($to)) {

      $from = \Drupal::config('system.site')->get('mail');
      if (empty($from)) {
        $from = ini_get('sendmail_from');
      }

      $headers = array(
        'Reply-to' => $from,
        'Return-path' => "<$from>",
        'Errors-to' => $from,
      );
      $recipients = explode(',', $to);
      foreach ($recipients as $recipient) {
        $recipient = trim($recipient);
        $params = array(
          'subject' => $subject,
          'message' => strtr($message, $variables),
          'headers' => $headers,
        );
        $language = \Drupal::service('language.default')->get()->getId();
        if ($user = user_load_by_mail($recipient)) {
          $language = $user->getPreferredLangcode();
        }
        drupal_mail('inactive_user', 'inactive_user_notice', $recipient, $language, $params, $from, TRUE);
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function inactiveUserWithContent($uid) {
    $user_has_nodes = $this->database->select('node', 'n')
        ->fields('n', ['uid'])
        ->condition('n.uid', $uid)
        ->execute()->rowcount();
    $user_has_comments = $this->database->select('comment', 'c')
        ->fields('c', array('uid'))
        ->condition('c.uid', $uid)
        ->execute()->rowcount();

    return ($user_has_nodes + $user_has_comments) > 0;
  }

  /**
   * Helper function to prepare site name variable.
   */
  protected function getSiteName() {
    $this->siteName = $this->configFactory->get('system.site')->get('name');
    if (empty($this->siteName)) {
      $this->siteName = 'Drupal';
    }
  }

}
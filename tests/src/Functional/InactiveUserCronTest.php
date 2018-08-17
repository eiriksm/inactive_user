<?php

namespace Drupal\Tests\inactive_user\Functional;

use Drupal\Tests\BrowserTestBase;

/**
 * Simple test to ensure that main page loads with module enabled.
 *
 * @group inactive_user
 */
class InactiveUserCronTest extends BrowserTestBase {

  /**
   * Modules to enable.
   *
   * @var array
   */
  public static $modules = ['inactive_user', 'user'];

  /**
   * A user with permission to administer site configuration.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $user;

  /**
   * {@inheritdoc}
   */
  protected function setUp() {
    parent::setUp();
    $this->user = $this->drupalCreateUser(['administer site configuration']);
  }

  /**
   * Tests that the home page loads with a 200 response.
   */
  public function runCron() {
    print_r($this->user);
    $this->drupalLogin($this->user);
  }

}

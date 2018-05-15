<?php
if(!defined('ABSPATH')) {die('You are not allowed to call this page directly.');}

class MeprDbMigrations {
  private $migrations;

  public static function run($from_version, $to_version) {
    global $wpdb;

    $mig = new MeprDbMigrations();
    $migration_versions = $mig->get_migration_versions($from_version, $to_version);

    if(empty($migration_versions)) { return; }

    foreach($migration_versions as $migration_version) {
      $config = $mig->get_migration($migration_version);
      foreach($config['migrations'] as $callbacks) {
        // Store current migration config in the database so the
        // progress AJAX endpoint can see what's going on
        set_transient('mepr_current_migration', $callbacks, MeprUtils::hours(4));
        call_user_func(array($mig, $callbacks['migration']));
        delete_transient('mepr_current_migration');
      }
    }
  }

  public static function show_upgrade_ui($from_version, $to_version) {
    $mig = new MeprDbMigrations();
    $migration_versions = $mig->get_migration_versions($from_version, $to_version);

    if(empty($migration_versions)) { return; }

    foreach($migration_versions as $migration_version) {
      $config = $mig->get_migration($migration_version);
      if( isset($config['show_ui']) &&
          $config['show_ui'] !== false &&
          call_user_func(array($mig, $config['show_ui'])) ) {
        return true;
      }
    }

    return false;
  }

  public function __construct() {
    // ensure migration versions are sequential when adding new migration callbacks
    $this->migrations = array(
      '1.3.0' => array(
        'show_ui' => 'show_ui_001_002',
        'migrations' => array(
          array(
            'migration' => 'create_and_migrate_subscriptions_table_001',
            'check'     => 'check_create_and_migrate_subscriptions_table_001',
            'message'   => __('Updating Subscriptions', 'memberpress'),
          ),
          array(
            'migration' => 'create_and_migrate_members_table_002',
            'check'     => 'check_create_and_migrate_members_table_002',
            'message'   => __('Optimizing Member Data', 'memberpress'),
          ),
        ),
      ),
      '1.3.9' => array(
        'show_ui' => false,
        'migrations' => array(
          array(
            'migration' => 'add_trial_txn_count_column_to_members_table_003',
            'check'     => false,
            'message'   => false,
          ),
          array(
            'migration' => 'sub_post_meta_to_table_token_004',
            'check'     => false,
            'message'   => false,
          ),
        ),
      ),
      '1.3.11' => array(
        'show_ui' => false,
        'migrations' => array(
          array(
            'migration' => 'fix_all_the_expires_006',
            'check'     => false,
            'message'   => false,
          )
        )
      )
    );
  }

  public function get_migration_versions($from_version, $to_version) {
    $mig_versions = array_keys($this->migrations);

    $versions = array();
    foreach($mig_versions as $mig_version) {
      if(version_compare($from_version, $mig_version, '<')) {
         //version_compare($to_version, $mig_version, '>='))
        $versions[] = $mig_version;
      }
    }

    return $versions;
  }

  public function get_migration($version) {
    return $this->migrations[$version];
  }

/** SHOW UI **/
  public function show_ui_001_002() {
    global $wpdb;
    $mepr_db = new MeprDb();

    $q = "
      SELECT COUNT(*)
        FROM {$wpdb->posts}
       WHERE post_type='mepr-subscriptions'
    ";

    if($mepr_db->table_exists($mepr_db->subscriptions)) {
      $q .= "
        AND ID NOT IN (
          SELECT id
            FROM {$mepr_db->subscriptions}
        )
      ";
    }

    $subs_left = $wpdb->get_var($q);

    $q = "
      SELECT COUNT(*)
        FROM {$wpdb->users}
    ";

    if($mepr_db->table_exists($mepr_db->members)) {
      $q .= "
        WHERE ID NOT IN (
         SELECT user_id
           FROM {$mepr_db->members}
        )
      ";
    }

    $members_left = $wpdb->get_var($q);

    $already_migrating = get_transient('mepr_migrating');

    return (
      !empty($already_migrating) ||
      ($subs_left >= 100) ||
      ($members_left >= 100)
    );
  }

/** CHECKS **/
  public function check_create_and_migrate_subscriptions_table_001() {
    global $wpdb;
    $mepr_db = MeprDb::fetch();

    $q = $wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type=%s", 'mepr-subscriptions');
    $total = $wpdb->get_var($q); //Need to account for 0's below

    $q = "SELECT COUNT(*) FROM {$mepr_db->subscriptions}";
    $completed = $wpdb->get_var($q);

    $progress = 100;
    if($total > 0) {
      $progress = (int)(($completed / $total) * 100);
      $progress = min($progress, 100);
    }

    return compact('completed','total','progress');
  }

  public function check_create_and_migrate_members_table_002() {
    global $wpdb;
    $mepr_db = MeprDb::fetch();

    $q = "SELECT COUNT(*) FROM {$wpdb->users}";
    $total = $wpdb->get_var($q); //Should never get a 0 here

    $q = "SELECT COUNT(*) FROM {$mepr_db->members}";
    $completed = $wpdb->get_var($q);

    $progress = (int)(($completed / $total) * 100);
    $progress = min($progress, 100);

    return compact('completed','total','progress');
  }

/** MIGRATIONS **/
  public function create_and_migrate_subscriptions_table_001() {
    global $wpdb;
    $mepr_db = MeprDb::fetch();

    MeprSubscription::upgrade_table(null,true);

    $max_sub_id = $wpdb->get_var("SELECT max(ID) FROM {$wpdb->posts} WHERE post_type='mepr-subscriptions'");

    if(!empty($max_sub_id)) {
      $max_sub_id = (int)$max_sub_id + 1; // Just in case
      $wpdb->query("ALTER TABLE {$mepr_db->subscriptions} AUTO_INCREMENT={$max_sub_id}");
    }
  }

  public function create_and_migrate_members_table_002() {
    MeprUser::update_all_member_data(true);
  }

  public function add_trial_txn_count_column_to_members_table_003() {
    MeprUser::update_all_member_data(false, '', array('txn_count', 'active_txn_count', 'expired_txn_count', 'trial_txn_count'));
  }

  public function sub_post_meta_to_table_token_004() {
    global $wpdb;

    $mepr_db = MeprDb::fetch();

    $q = $wpdb->prepare("
        SELECT *
          FROM {$wpdb->postmeta}
         WHERE meta_key IN (%s,%s,%s,%s)
      ",
      '_mepr_authnet_order_invoice', //Use actual string here, becasue Authorize.net Class doens't exist in business edition and what if we change the class names in the future?
      '_mepr_paypal_token',
      '_mepr_paypal_pro_token',
      '_mepr_stripe_plan_id'
    );

    $tokens = $wpdb->get_results($q);

    foreach($tokens as $token) {
      $q = $wpdb->prepare("
          UPDATE {$mepr_db->subscriptions}
             SET token=%s
           WHERE id=%d
        ",
        $token->meta_value,
        $token->post_id
      );

      $wpdb->query($q);
    }
  }

  public function fix_all_the_expires_006() {
    global $wpdb;
    $mepr_db = MeprDb::fetch();

    //Gimme all the transactions since 2017-07-15 with trials
    $query = $wpdb->prepare("
      SELECT t.id
      FROM {$mepr_db->transactions} t
      JOIN {$mepr_db->subscriptions} s
        ON s.id = t.subscription_id
      WHERE s.trial_days > 0
        AND t.status = %s
        AND t.created_at > '2017-07-15'
    ",
    MeprTransaction::$complete_str
    );

    $transactions = $wpdb->get_results($query);
    foreach($transactions as $transaction_id) {
      $transaction = new MeprTransaction($transaction_id->id);
      $subscription = $transaction->subscription();
      //Get the expiratoin with the bug fix
      $txn_created_at = strtotime($transaction->created_at);
      $expected_expiration = $subscription->get_expires_at($txn_created_at);
      $expires_at = MeprUtils::ts_to_mysql_date($expected_expiration);
      //Do we actually need to fix anything?
      if($expires_at != $transaction->expires_at) {
        //We're just going to do this via SQL to skip hooks
        MeprUtils::debug_log("Found transaction {$transaction->id} to update from {$transaction->expires_at} to {$expires_at}");
        $wpdb->update($mepr_db->transactions, array('expires_at' => $expires_at), array('id' => $transaction->id));
      }
    }
  }
}

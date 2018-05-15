<?php
if(!defined('ABSPATH')) {die('You are not allowed to call this page directly.');}

class MeprSubscriptionsCtrl extends MeprBaseCtrl
{
  public function load_hooks()
  {
    add_action('admin_enqueue_scripts',               array($this, 'enqueue_scripts'));
    add_action('wp_ajax_mepr_subscr_num_search',      array($this, 'subscr_num_search'));
    add_action('wp_ajax_mepr_subscr_edit_status',     array($this, 'edit_subscr_status'));
    add_action('wp_ajax_mepr_delete_subscription',    array($this, 'delete_subscription'));
    add_action('wp_ajax_mepr_suspend_subscription',   array($this, 'suspend_subscription'));
    add_action('wp_ajax_mepr_resume_subscription',    array($this, 'resume_subscription'));
    add_action('wp_ajax_mepr_cancel_subscription',    array($this, 'cancel_subscription'));
    add_action('wp_ajax_mepr_subscriptions',          array($this, 'csv'));
    add_action('wp_ajax_mepr_lifetime_subscriptions', array($this, 'lifetime_csv'));
    add_action('mepr_control_table_footer',           array($this, 'export_footer_link'), 10, 3);

    // Screen Options
    $hook = $this->get_hook();
    add_action( "load-{$hook}", array($this,'add_recurring_screen_options') );
    add_filter( "manage_{$hook}_columns", array($this, 'get_columns') );

    $hook = $this->get_hook(true);
    add_action( "load-{$hook}", array($this,'add_lifetime_screen_options') );
    add_filter( "manage_{$hook}_columns", array($this, 'get_lifetime_columns') );

    add_filter( 'set-screen-option', array($this,'setup_screen_options'), 10, 3 );

    add_action('mepr_table_controls_search', array($this, 'table_search_box'));
  }

  private function get_hook($lifetime=false) {
    if($lifetime) { return 'admin_page_memberpress-lifetimes'; }
    return 'memberpress_page_memberpress-subscriptions';
  }

  private function is_lifetime() {
    $screen = get_current_screen();

    if( isset($screen) && is_object($screen) ) {
      return ( $this->get_hook(true) === $screen->id );
    }
    else if( isset($_GET['page']) ) {
      return ( 'memberpress-lifetimes' === $_GET['page'] );
    }
    else
      return false;
  }

  public function listing() {
    $screen = get_current_screen();
    $lifetime = ( $screen->id === $this->get_hook(true) );
    $sub_table = new MeprSubscriptionsTable( $screen, $this->get_columns(), $lifetime );
    $sub_table->prepare_items();

    MeprView::render('/admin/subscriptions/list', get_defined_vars());
  }

  public function enqueue_scripts($hook)
  {
    if( $hook === $this->get_hook() || $hook === $this->get_hook(true) )
    {
      $l10n = array( 'del_sub' => __('A Subscription should be cancelled (at the Gateway or here) by you, or by the Member on their Account page before being removed. Deleting an Active Subscription can cause future recurring payments not to be tracked properly. Are you sure you want to delete this Subscription?', 'memberpress'),
                     'del_sub_error' => __('The Subscription could not be deleted. Please try again later.', 'memberpress'),
                     'cancel_sub' => __('This will cancel all future payments for this subscription. Are you sure you want to cancel this Subscription?', 'memberpress'),
                     'cancel_sub_error' => __('The Subscription could not be cancelled here. Please login to your gateway\'s virtual terminal to cancel it.', 'memberpress'),
                     'cancel_sub_success' => __('The Subscription was successfully cancelled.', 'memberpress'),
                     'cancelled_text' => __('Stopped', 'memberpress'),
                     'suspend_sub' => __("This will stop all payments for this subscription until the user logs into their account and resumes.\n\nAre you sure you want to pause this Subscription?", 'memberpress'),
                     'suspend_sub_error' => __('The Subscription could not be paused here. Please login to your gateway\'s virtual terminal to pause it.', 'memberpress'),
                     'suspend_sub_success' => __('The Subscription was successfully paused.', 'memberpress'),
                     'suspend_text' => __('Paused', 'memberpress'),
                     'resume_sub' => __("This will resume payments for this subscription.\n\nAre you sure you want to resume this Subscription?", 'memberpress'),
                     'resume_sub_error' => __('The Subscription could not be resumed here. Please login to your gateway\'s virtual terminal to resume it.', 'memberpress'),
                     'resume_sub_success' => __('The Subscription was successfully resumed.', 'memberpress'),
                     'resume_text' => __('Enabled', 'memberpress'),
                     'delete_subscription_nonce' => wp_create_nonce('delete_subscription'),
                     'suspend_subscription_nonce' => wp_create_nonce('suspend_subscription'),
                     'update_status_subscription_nonce' => wp_create_nonce('update_status_subscription'),
                     'resume_subscription_nonce' => wp_create_nonce('resume_subscription'),
                     'cancel_subscription_nonce' => wp_create_nonce('cancel_subscription')
                   );

      wp_enqueue_style('mepr-subscriptions-css', MEPR_CSS_URL.'/admin-subscriptions.css', array(), MEPR_VERSION);
      wp_enqueue_script('mepr-subscriptions-js', MEPR_JS_URL.'/admin_subscriptions.js', array('jquery'), MEPR_VERSION);
      wp_enqueue_script('mepr-table-controls-js', MEPR_JS_URL.'/table_controls.js', array('jquery'), MEPR_VERSION);
      wp_localize_script('mepr-subscriptions-js', 'MeprSub', $l10n);
    }
  }

  public function edit_subscr_status() {
    check_ajax_referer('update_status_subscription','mepr_subscriptions_nonce');

    if( !isset($_POST['id']) || empty($_POST['id']) ||
        !isset($_POST['value']) || empty($_POST['value']) )
      die(__('Save Failed', 'memberpress'));

    $id = sanitize_key($_POST['id']);
    $value = sanitize_text_field($_POST['value']);

    $sub = new MeprSubscription($id);
    if( empty($sub->id) )
      die(__('Save Failed', 'memberpress'));

    $sub->status = $value;
    $sub->store();

    echo MeprAppHelper::human_readable_status( $value, 'subscription' );
    die();
  }

  public function delete_subscription() {
    check_ajax_referer('delete_subscription','mepr_subscriptions_nonce');

    if(!MeprUtils::is_mepr_admin()) {
      die(__('You do not have access.', 'memberpress'));
    }

    if(!isset($_POST['id']) || empty($_POST['id']) || !is_numeric($_POST['id'])) {
      die(__('Could not delete subscription', 'memberpress'));
    }

    $sub = new MeprSubscription($_POST['id']);
    $sub->destroy();

    die('true'); //don't localize this string
  }

  public function suspend_subscription() {
    check_ajax_referer('suspend_subscription','mepr_subscriptions_nonce');

    if(!MeprUtils::is_mepr_admin()) {
      die(__('You do not have access.', 'memberpress'));
    }

    if(!isset($_POST['id']) || empty($_POST['id']) || !is_numeric($_POST['id'])) {
      die(__('Could not pause subscription', 'memberpress'));
    }

    $sub = new MeprSubscription($_POST['id']);
    $sub->suspend();

    die('true'); //don't localize this string
  }

  public function resume_subscription() {
    check_ajax_referer('resume_subscription','mepr_subscriptions_nonce');

    if(!MeprUtils::is_mepr_admin()) {
      die(__('You do not have access.', 'memberpress'));
    }

    if(!isset($_POST['id']) || empty($_POST['id']) || !is_numeric($_POST['id'])) {
      die(__('Could not resume subscription', 'memberpress'));
    }

    $sub = new MeprSubscription($_POST['id']);
    $sub->resume();

    die('true'); //don't localize this string
  }

  public function cancel_subscription() {
    check_ajax_referer('cancel_subscription','mepr_subscriptions_nonce');

    if(!MeprUtils::is_mepr_admin()) {
      die(__('You do not have access.', 'memberpress'));
    }

    if(!isset($_POST['id']) || empty($_POST['id']) || !is_numeric($_POST['id'])) {
      die(__('Could not cancel subscription', 'memberpress'));
    }

    $sub = new MeprSubscription($_POST['id']);

    try {
      $sub->cancel();
    }
    catch( Exception $e ) {
      die($e->getMessage());
    }

    die('true'); //don't localize this string
  }

  public function subscr_num_search() {
    if(!MeprUtils::is_mepr_admin()) {
      die('-1');
    }

    // jQuery suggest plugin has already trimmed and escaped user input (\ becomes \\)
    // so we just need to sanitize the username
    $s = sanitize_user($_GET['q']);

    if(strlen($s) < 5) { die(); } // require 5 chars for matching

    $subs = MeprSubscription::search_by_subscr_id($s);

    MeprView::render('/admin/subscriptions/search', get_defined_vars());
    die();
  }

  public function csv($lifetime = false) {
    check_ajax_referer('export_subscriptions', 'mepr_subscriptions_nonce');

    $filename = ( $lifetime ? 'non-recurring-' : '' ) . 'subscriptions-'.time();

    // Since we're running WP_List_Table headless we need to do this
    $GLOBALS['hook_suffix'] = false;

    $screen = get_current_screen();
    $tab = new MeprSubscriptionsTable( $screen, $this->get_columns(), $lifetime );

    if(isset($_REQUEST['all']) && !empty($_REQUEST['all'])) {
      $search  = isset($_REQUEST["search"]) && !empty($_REQUEST["search"]) ? esc_sql($_REQUEST["search"])  : '';
      $search_field = isset($_REQUEST["search"]) && !empty($_REQUEST["search-field"])  ? esc_sql($_REQUEST["search-field"])  : 'any';
      $search_field = isset($tab->db_search_cols[$search_field]) ? $tab->db_search_cols[$search_field] : 'any';

      if($lifetime) {
        $all = MeprSubscription::lifetime_subscr_table(
          /* $order_by */     'txn.created_at',
          /* $order */        'ASC',
          /* $paged */        '',
          /* $search */       $search,
          /* $search_field */ $search_field,
          /* $perpage */      '',
          /* $countonly */    false,
          /* $params */       $_REQUEST
        );
      }
      else {
        $all = MeprSubscription::subscr_table(
          /* $order_by */     'sub.created_at',
          /* $order */        'ASC',
          /* $paged */        '',
          /* $search */       $search,
          /* $search_field */ $search_field,
          /* $perpage */      '',
          /* $countonly */    false,
          /* $params */       $_REQUEST
        );
      }

      MeprUtils::render_csv($all['results'], $filename);
    }
    else {
      $tab->prepare_items();

      MeprUtils::render_csv( $tab->get_items(), $filename );
    }

  }

  public function lifetime_csv() {
    $this->csv(true);
  }

  public function export_footer_link($action, $totalitems, $itemcount) {
    if($action=='mepr_subscriptions' || $action=='mepr_lifetime_subscriptions') {
      MeprAppHelper::export_table_link($action, 'export_subscriptions', 'mepr_subscriptions_nonce', $itemcount);
      ?> | <?php
      MeprAppHelper::export_table_link($action, 'export_subscriptions', 'mepr_subscriptions_nonce', $totalitems, true);
    }
  }

  /* This is here to use wherever we want. */
  public function get_columns($lifetime=false) {
    $prefix = $lifetime ? 'col_txn_' : 'col_';
    $cols = array(
      $prefix.'id' => __('Id', 'memberpress'),
      $prefix.'subscr_id' => __('Subscription', 'memberpress'),
      // $prefix.'active' => __('Active', 'memberpress'),
      $prefix.'status' => __('Auto Rebill', 'memberpress'),
      $prefix.'product' => __('Membership', 'memberpress'),
      $prefix.'product_meta' => __('Terms', 'memberpress'),
      $prefix.'propername' => __('Name', 'memberpress'),
      $prefix.'member' => __('User', 'memberpress'),
      $prefix.'gateway' => __('Gateway', 'memberpress'),
      $prefix.'txn_count' => __('Transaction', 'memberpress'),
      $prefix.'ip_addr' => __('IP', 'memberpress'),
      $prefix.'created_at' => __('Created On', 'memberpress'),
      $prefix.'expires_at' => __('Expires On', 'memberpress')
    );

    if($lifetime) {
      unset($cols[$prefix.'status']);
      unset($cols[$prefix.'delete_sub']);
      unset($cols[$prefix.'txn_count']);
      $cols[$prefix.'subscr_id'] = __('Transaction', 'memberpress');
      $cols[$prefix.'product_meta'] = __('Price', 'memberpress');
    }

    return MeprHooks::apply_filters('mepr-admin-subscriptions-cols', $cols, $prefix, $lifetime);
  }

  public function get_lifetime_columns() {
    return $this->get_columns(true);
  }

  public function add_screen_options($optname='mp_subs_perpage') {
    add_screen_option( 'layout_columns' );
    add_screen_option( 'per_page', array(
        'label' => __('Subscriptions', 'memberpress'),
        'default' => 10,
        'option' => $optname
      )
    );
  }

  public function add_recurring_screen_options() {
    $this->add_screen_options('mp_subs_perpage');
  }

  public function add_lifetime_screen_options() {
    $this->add_screen_options('mp_lifetime_subs_perpage');
  }

  public function setup_screen_options($status, $option, $value) {
    $optname = $this->is_lifetime() ? 'mp_lifetime_subs_perpage' : 'mp_subs_perpage';
    if ( $optname === $option ) { return $value; }
    return $status;
  }

  public function table_search_box() {
    if(isset($_REQUEST['page']) && ($_REQUEST['page']=='memberpress-subscriptions' || $_REQUEST['page']=='memberpress-lifetimes')) {
      $mepr_options = MeprOptions::fetch();

      $membership = (isset($_REQUEST['membership'])?$_REQUEST['membership']:false);
      $status = (isset($_REQUEST['status'])?$_REQUEST['status']:'all');
      $gateway = (isset($_REQUEST['gateway'])?$_REQUEST['gateway']:'all');

      $prds = MeprCptModel::all('MeprProduct');
      $gateways = $mepr_options->payment_methods();

      MeprView::render('/admin/subscriptions/search_box', compact('membership','status','prds','gateways','gateway'));
    }
  }
} //End class

<?php
if(!defined('ABSPATH')) {die('You are not allowed to call this page directly.');}
/*
Integration of bbPress into MemberPress
*/
class MeprBbPressCtrl extends MeprBaseCtrl {
  public function load_hooks() {
    //Used to hide forums & topics
    add_filter('bbp_get_forum_visibility', 'MeprBbPressCtrl::hide_forums', 11, 2);
    add_filter('bbp_get_hidden_forum_ids', 'MeprBbPressCtrl::hide_threads');

    //We're only allowing blocking by forum
    add_filter('mepr-rules-cpts', 'MeprBbPressCtrl::filter_rules_cpts');
  }

  public static function hide_threads($ids) {
    $all_forums = get_posts(array('post_type' => 'forum', 'numberposts' => -1));
    $call       = function_exists('debug_backtrace')?debug_backtrace():array();
    $to_hide    = array();

    if(!empty($all_forums)) {
      foreach($all_forums as $forum) {
        if(MeprRule::is_locked($forum)) {
          $to_hide[] = $forum->ID;
        }
      }
    }

    foreach($call as $c) {
      // We only want to hide in indexes or searches for now
      if( $c['function'] == 'display_topic_index' ||
          $c['function'] == 'display_search' ) {
        $ids = array_merge($ids, $to_hide);
      }
    }

    return $ids;
  }

  //Used mostly for redirecting to the login or unauthorized page if the current forum is locked
  public static function hide_forums($status, $forum_id) {
    static $already_here;
    if(isset($already_here) && $already_here) { return $status; }
    $already_here = true;

    $mepr_options = MeprOptions::fetch();
    $forum        = get_post($forum_id);
    $uri          = urlencode($_SERVER['REQUEST_URI']);

    $actual_forum_id = bbp_get_forum_id();
    $forum = get_post($actual_forum_id);

    //Not a singular view, then let's bail
    if(!is_singular()) { return $status; }

    //Let moderators and keymasters see everything
    if(current_user_can('edit_others_topics')) { return $status; }

    if(!isset($forum)) { return $status; }

    if(MeprRule::is_locked($forum)) {
      if(!headers_sent()) {
        if($mepr_options->redirect_on_unauthorized) {
          $delim = MeprAppCtrl::get_param_delimiter_char($mepr_options->unauthorized_redirect_url);
          $redirect_to = "{$mepr_options->unauthorized_redirect_url}{$delim}mepr-unauth-page={$forum->ID}&redirect_to={$uri}";
        }
        else {
          $redirect_to = $mepr_options->login_page_url("action=mepr_unauthorized&mepr-unauth-page={$forum->ID}&redirect_to=".$uri);
          $redirect_to = (MeprUtils::is_ssl())?str_replace('http:', 'https:', $redirect_to):$redirect_to;
        }
        MeprUtils::wp_redirect($redirect_to);
        exit;
      }
      else {
        $status = 'hidden';
      }
    }

    return $status;
  }

  public static function filter_rules_cpts($cpts) {
    unset($cpts['reply']);
    unset($cpts['topic']);

    return $cpts;
  }
} //End class

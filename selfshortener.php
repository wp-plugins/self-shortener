<?php
/**
 * @package Self_Shortener
 * @author Giraffy Inc.
 * @version 0.1.2
 */
/*
Plugin Name: Self Shortener
Plugin URI: http://giraffy.jp/products_en/wordpress/selfshortener/
Description: URL Shortener by your WordPress itself.
Version: 0.1.2
Author: Giraffy Inc.
Author URI: http://giraffy.jp/index_en/
License: GPL2
Text Domain: selfshortener
*/
/*
    Self Shortener: URL Shortener by your WordPress itself.
    Copyright (C) 2010  Giraffy Inc.

    This program is free software; you can redistribute it and/or
    modify it under the terms of the GNU General Public License
    as published by the Free Software Foundation; either version 2
    of the License, or (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program.  If not, see <http://www.gnu.org/licenses/>.
*/

define("SELFSHORTENER_OPTIONS_KEY", "selfshortener_options");
define("SELFSHORTENER_KEY_LENGTH_DEFAULT", 4);
define("SELFSHORTENER_MAX_KEY_LENGTH", 20);
define("SELFSHORTENER_URL_TABLE", "selfshortener_url");

/* register hooks */
register_activation_hook(__FILE__, 'selfshortener_install');
add_action('init', 'selfshortener_unshorten_url');
add_action('init', 'selfshortener_validate_link_key');
add_action('admin_menu', 'selfshortener_plugin_menu');
add_action('admin_print_scripts', 'selfshortener_setup_script');
add_action('admin_head', 'selfshortener_admin_head');

load_plugin_textdomain('selfshortener', false,
		       basename(dirname(plugin_basename(__FILE__))) . "/lang");

/* Installer function called by activation hook.  Create or update
 * table and options. */
function selfshortener_install() {
    global $wpdb;
    $table = $wpdb->prefix . SELFSHORTENER_URL_TABLE;
    if ($wpdb->get_var("SHOW TABLES LIKE '$table'") != $table) {
	$sql = "CREATE TABLE $table ("
	    . "id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, "
	    . "author BIGINT(20) unsigned NOT NULL, "
	    . "ukey VARCHAR(20) NOT NULL UNIQUE, "
	    . "url VARCHAR(255) NOT NULL, "
	    . "createtime TIMESTAMP DEFAULT CURRENT_TIMESTAMP, "
	    . "enabled BOOLEAN DEFAULT TRUE, "
	    . "INDEX (author), "
	    . "INDEX (url), "
	    . "INDEX (createtime) "
	    . ") CHARACTER SET 'utf8'";
	require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
	dbDelta($sql);
    }
    selfshortener_set_option('version', "0.1");
}

/* Main function to parse shorten URL and redirect to Link-To URL.
 * This function called by init hook */
function selfshortener_unshorten_url() {
    $prefix = selfshortener_get_prefix();
    $uri = $_SERVER['REQUEST_URI'];
    if (!preg_match("!^{$prefix}([-0-9a-zA-Z_.]+)!", $uri, $m)) return;

    $key = $m[1];
    $urlinfo = selfshortener_get_url_info($key);
    if ($urlinfo && is_array($urlinfo) && !empty($urlinfo['url'])) {
	wp_redirect($urlinfo['url'], 301);
	exit;
    }
}

/* Validate link_key (character set, length, duplication) and return
 * error message as plain text.  If key is valid, return with empty.
 *
 * key: login_key to validate
 * id (optional): current link id to exclude from validation check
 */
function selfshortener_validate_link_key() {
    if (!is_admin()) return;
    if (selfshortener_get_request_param('page') !=
		'selfshortener-validate-link-key') {
	return;
    }
    header("Content-Type: text/plain; charset=UTF-8");

    $key = selfshortener_get_request_param('key');
    $id = intval(selfshortener_get_request_param('id'));
    if ($key === null || $key === '') {
	exit;
    }

    if (strlen($key) > SELFSHORTENER_MAX_KEY_LENGTH) {
	_e('Link Key too long', 'selfshortener');
    } else if (preg_match('/[^-0-9A-Za-z._]/', $key)) {
	_e('Invalid Link Key: you can only use alphanumeric and "-_,".',
	   'selfshortener');
    } else if (selfshortener_exists_key($key, $id)) {
	_e('Link Key already used.', 'selfshortener');
    }
    exit;
}

/* Setup administration menu of Self Shortener.  This function called
 * by admin_menu hook */
function selfshortener_plugin_menu() {
    add_menu_page('Self Shortener', 'Self Shortener', 'author',
		  'selfshortener-menu',
		  'selfshortener_new_link');
    add_submenu_page('selfshortener-menu',
		     __('Edit Link', 'selfshortener'),
		     __('New Link', 'selfshortener'),
		     'edit_posts',
		     'selfshortener-edit',
		     'selfshortener_edit_link');
    add_submenu_page('selfshortener-menu',
		     __('Manage Links', 'selfshortener'),
		     __('Manage Links', 'selfshortener'),
		     'edit_posts',
		     'selfshortener-manage',
		     'selfshortener_manage_links');
    add_submenu_page('selfshortener-menu',
		     __('General Options', 'selfshortener'),
		     __('General Options', 'selfshortener'),
		     'administrator',
		     'selfshortener-options',
		     'selfshortener_options');
}

// setup script (and style) for selfshortener-edit
function selfshortener_setup_script() {
    if (selfshortener_get_request_param('page') != 'selfshortener-edit')
	return;
    // Use jQuery
    wp_enqueue_script('jquery');
}

function selfshortener_get_option($name) {
    $a = get_option(SELFSHORTENER_OPTIONS_KEY);
    return ($a && is_array($a) && isset($a[$name])) ? $a[$name] : null;
}
    
function selfshortener_set_option($name, $val) {
    $a = get_option(SELFSHORTENER_OPTIONS_KEY);
    if (!$a || !is_array($a)) {
	$a = array($name => $val);
	add_option(SELFSHORTENER_OPTIONS_KEY, $a, '', 'no');
    } else {
	$a[$name] = $val;
	update_option(SELFSHORTENER_OPTIONS_KEY, $a);
    }
}

function selfshortener_get_prefix() {
    $prefix = selfshortener_get_option("prefix");
    return ($prefix ? $prefix : selfshortener_get_prefix_default());
}

function selfshortener_get_prefix_default() {
    $siteurl = get_bloginfo('url');
    $baseurl = preg_replace('!^[A-Za-z]+://[^/]*!', '', $siteurl);
    if (!preg_match('!^/!', $baseurl)) {
	$baseurl = '/' . $baseurl;
    }
    if (!preg_match('!/$!', $baseurl)) {
	$baseurl .= '/';
    }
    $prefix = $baseurl . "g/";
    return $prefix;
}

function selfshortener_get_link_base_url() {
    $siteurl = get_bloginfo('url');
    $siteurl = preg_replace('!^([A-Za-z]+://[^/]*)(/.*)!', "$1", $siteurl);
    return ($siteurl . selfshortener_get_prefix());
}

function selfshortener_get_link_url($key) {
    return (selfshortener_get_link_base_url() . $key);
}

function selfshortener_get_url_info($key, $force=0) {
    global $wpdb;
    $table = $wpdb->prefix . SELFSHORTENER_URL_TABLE;
    $sql = $wpdb->prepare("SELECT url FROM $table WHERE ukey = %s"
			  . ($force ? "" : " AND enabled = TRUE"), $key);
    return $wpdb->get_row($sql, ARRAY_A);
}

function selfshortener_load_link($id) {
    global $wpdb;

    $table = $wpdb->prefix . SELFSHORTENER_URL_TABLE;
    $user_table = $wpdb->prefix . "users";
    $sql = "SELECT t.id, t.author, "
	. "COALESCE(u.display_name, u.user_login, t.author) AS author_name, "
	. "t.ukey AS link_key, t.url AS link_to_url, t.createtime, t.enabled "
	. "FROM $table t LEFT JOIN $user_table u "
	. "ON (u.ID = t.author) "
	. "WHERE t.id = $id ";
    return $wpdb->get_row($sql, ARRAY_A);
}

function selfshortener_exists_key($key, $exclude_id=0) {
    global $wpdb;
    if ($key == "") return false;
    $table = $wpdb->prefix . SELFSHORTENER_URL_TABLE;
    $sql = "SELECT id FROM $table "
	. $wpdb->prepare("WHERE ukey = %s ", $key)
	. ($exclude_id > 0 ? $wpdb->prepare("AND id <> %d ", $exclude_id) : "");
    return ($wpdb->get_var($sql) > 0);
}

function selfshortener_gen_key($key_length, $max_retry=10) {
    $stock = "0123456789abcdefghijklmnopqrstuvwxyz"
	. "ABCDEFGHIJKLMNOPQRSTUVWXYZ";
    $chars = preg_split('//', $stock, -1, PREG_SPLIT_NO_EMPTY);
    $max = count($chars) - 1;
    for ($retry = 0; $retry < $max_retry; $retry++) {
	$key = '';
	for ($i = 0; $i < $key_length; $i++) {
	    $key .= $chars[mt_rand(0, $max)];
	}
        if (!selfshortener_exists_key($key)) {
	    return $key;
	}
    }
    return null;
}

function selfshortener_get_key_length() {
    $length = intval(selfshortener_get_option("key_length"));
    return ($length > 0) ? $length : SELFSHORTENER_KEY_LENGTH_DEFAULT;
}

function selfshortener_admin_head() {
    if (selfshortener_get_request_param('page') != 'selfshortener-edit')
	return;

?><script type="text/javascript">
jQuery(document).ready(selfshortener_init);

/* Init function */
function selfshortener_init() {
    jQuery('#link_key').keyup(function(event) {
	    var target = jQuery(event.target);
	    var val = target.val();
	    var id = jQuery('#id').val();
	    var param = {
		"page": "selfshortener-validate-link-key", "key": val
	    };
	    if (id) {
		param['id'] = id;
	    }
	    jQuery('#short_url_link_key').html(val);
	    jQuery.get("<?php echo admin_url('admin.php') ?>",
		       param, function(data) {
		target.parent().find('span.error').remove();
		if (!data) return;
		target.parent().append('<span class="error">'
			     + data + '</span>');
	    });
	});
}
</script>
<style type="text/css">
 #short_url_link_key {font-style: italic; font-weight: bold; }
</style>
<?php
}

function selfshortener_edit_link() {
    global $wpdb, $user_ID;

    if (!current_user_can('edit_posts')) {
	return;
    }

    $table = $wpdb->prefix . SELFSHORTENER_URL_TABLE;
    $user_table = $wpdb->prefix . "users";

    $link = null;
    $link_id = intval(selfshortener_get_request_param('id'));
    $action = selfshortener_get_request_param('action');
    $key_length = selfshortener_get_key_length();

    if ($link_id > 0) {
	$link = selfshortener_load_link($link_id);
    }
    if (!$link) {
	$link = array(
	    'id' => 0,
	    'link_key' => '',
	    'link_to_url' => '',
	    'author' => '',
	    'author_name' => '',
	);
    }

    if ($link['id'] > 0
	    && !current_user_can('edit_others_posts')
	    && $link['author'] != $user_ID) { ?>
<p><?php _e("Permission denied", 'selfshortener') ?></p>
<?php	
	return;
    }

    $errors = array();
    if (!empty($_REQUEST['delete']) && $action == 'update' && $link['id'] > 0) {
	$wpdb->update($table, array('enabled' => 0), array('id' => $link['id']),
		      array("%d"), array('%d'));
	$link = selfshortener_load_link($link['id']);
    } if (!empty($_REQUEST['restore'])
		&& $action == 'update' && $link['id'] > 0) {
	$wpdb->update($table, array('enabled' => 1), array('id' => $link['id']),
		      array("%d"), array('%d'));
	$link = selfshortener_load_link($link['id']);
    } else if ($action == 'update') {
	$link_to_url = trim(selfshortener_get_request_param('link_to_url'));
	$link_key = trim(selfshortener_get_request_param('link_key'));
	$key_length = trim(selfshortener_get_request_param('key_length'));

	if (!preg_match('!^(https?://|/)!', $link_to_url)) {
	    $errors['link_to_url'] = __('Link-To URL must be started with "http://", "https://", or "/".', 'selfshortener');
	}

	if ($key_length == "") {
	    $key_length = selfshortener_get_key_length();
	} else if (!preg_match('/^[0-9]{1,5}$/', $key_length)) {
	    $errors["key_length"] =
		__("Invalid Link Key Length format", 'selfshortener');
	} else {
	    $key_length = intval($key_length);
	    if ($key_length < 1) {
		$errors["key_length"] =
		    __("Link Key Length too small", 'selfshortener');
	    } else if ($key_length > SELFSHORTENER_MAX_KEY_LENGTH) {
		$errors["key_length"] =
		    __("Link Key Length too large", 'selfshortener');
	    }
	}

	if ($link['link_key'] && $link['link_key'] != $link_key) {
	    $link['old_link_key'] = $link['link_key'];
	}
	if ($link_key == "") {
	    $link_key = selfshortener_gen_key($key_length);
	    if ($link_key === null) {
		$errors['link_key'] =
		    __("Link Key generation failed, "
		       . "try again using longer key length.",
		       "shortenter");
	    }
	} else if (strlen($link_key) < 1) {
	    $errors['link_key'] = __('Link Key too short', 'selfshortener');
	} else if (strlen($link_key) > SELFSHORTENER_MAX_KEY_LENGTH) {
	    $errors['link_key'] = __('Link Key too long', 'selfshortener');
	} else if (preg_match('/[^-0-9A-Za-z._]/', $link_key)) {
	    $errors['link_key'] = __('Invalid Link Key: '
		. 'you can only use alphanumeric and "-_,".', 'selfshortener');
	} else if (selfshortener_exists_key($link_key, $link['id'])) {
	    $errors['link_key'] = __('Link Key already used.', 'selfshortener');
	}

	$link['link_to_url'] = $link_to_url;
	$link['link_key'] = $link_key;

	if (count($errors) == 0) {
	    $data = array('author' => $user_ID,
			  'ukey' => $link_key,
			  'url' => $link_to_url);
	    $fmt = array('%d', '%s', '%s');
	    if ($link['id'] > 0) {
		$wpdb->update($table, $data, array('id' => $link['id']),
			      $fmt, array('%d'));
	    } else {
		$wpdb->insert($table, $data, $fmt);
		$link['id'] = $wpdb->get_var("SELECT LAST_INSERT_ID()");
	    }
	    $olk = $link['old_link_key'];
	    $link = selfshortener_load_link($link['id']);
	    $link['old_link_key'] = $olk;
	}
    }
?>
<h2><?php _e('Edit Link', 'selfshortener') ?></h2>

<p><a href="<?php echo admin_url('admin.php'),
	'?page=selfshortener-manage"' ?>"><?php
    _e('Manage Links', 'selfshortener') ?></a>
 &nbsp; 
 <a href="<?php echo admin_url('admin.php'),
	'?page=selfshortener-edit"' ?>"><?php
    _e('New Link', 'selfshortener') ?></a></p>

<form action="<?php echo admin_url('admin.php'),
	"?page=selfshortener-edit" ?>" method="post">
<table class="form-table">
<tbody>
 <tr>
  <th><lable for="link_to_url"><?php _e("Link-To URL", "selfshortener") ?></label></th>
  <td><input id="link_to_url" name="link_to_url" style="width: 100%" type="text" value="<?php echo esc_attr($link['link_to_url']) ?>" /><?php
    if (!empty($errors['link_to_url'])) {
    ?><br /><span class="error"><?php
    echo esc_html($errors['link_to_url']) ?></span><?php
    } ?><br />
   <span class="description"><?php
    _e("Input Long URL to shorten.", "selfshortener"); ?></span>
  </td>
 </tr>
 <tr>
  <th><lable for="link_key"><?php _e("Link Key", "selfshortener") ?></label></th>
  <td><input id="link_key" name="link_key" type="text" maxlength="<?php
    echo SELFSHORTENER_MAX_KEY_LENGTH ?>" value="<?php
    echo esc_attr($link['link_key']) ?>" />
  <?php if (!$link['id']) { ?>
   <span class="description"><?php _e("(optional)", "selfshortener") ?></span>
  <?php } ?>
   <br />
   <span class="description"><?php
    echo esc_html(sprintf(__("You can specify arbitrary link key (%d-%d), or generate random key", "selfshortener"), 1, SELFSHORTENER_MAX_KEY_LENGTH)) ?></span>
   <br />
<?php
    if (!empty($errors['link_key'])) {
    ?><span class="error"><?php
    echo esc_html($errors['link_key']) ?></span><?php } ?>
  </td>
 </tr>
 <tr>
  <th><lable for="key_length"><?php
     _e("Link Key Length", "selfshortener") ?></label></th>
  <td><input id="key_length" name="key_length" type="text" class="small-text" maxlength="5" value="<?php echo esc_attr($key_length) ?>" />
   <span class="description"><?php
    echo esc_html(sprintf(__("You can change link key length for random generation (%d-%d)", "selfshortener"), 1, SELFSHORTENER_MAX_KEY_LENGTH)); ?></span>
   <br />
<?php
    if (!empty($errors['key_length'])) {
    ?><span class="error"><?php
    echo esc_html($errors['key_length']) ?></span><?php } ?>
  </td>
 </tr>
<?php if (!empty($link['old_link_key'])) { ?>
 <tr>
  <th><?php _e("Old Link Key", "selfshortener") ?></th>
  <td><?php echo esc_html($link['old_link_key']) ?></td>
 </tr>
<?php } ?>
 <tr>
  <th><?php _e("Short URL", "selfshortener") ?></label></th>
  <td><span id="short_url"><?php
    echo esc_html(selfshortener_get_link_base_url())
    ?><span id="short_url_link_key"><?php
    echo esc_html($link['link_key']) ?></span></span></td>
 </tr>
<?php if ($link['id'] > 0) { ?>
 <tr>
  <th><?php _e("Status", "selfshortener") ?></th>
  <td><?php
    echo esc_html($link['enabled'] 
		  ? __('Registered', 'selfshortener')
		  : __('Deleted', 'selfshortener')); ?></td>
 </tr>
 <tr>
  <th><?php _e("Author", 'selfshortener') ?></th>
  <td><?php echo esc_html($link['author_name']) ?></td>
 </tr>
 <tr>
  <th><?php _e("Create Time", 'selfshortener') ?></th>
  <td><?php echo esc_html($link['createtime']) ?></td>
 </tr>
<?php } else if ($link['link_key']) { ?>
 <tr>
  <th><?php _e("Status", "selfshortener") ?></th>
  <td><?php _e("Not yet registered", "selfshortener") ?></td>
 </tr>
<?php } ?>
</tbody>
</table>

<p class="submit">
 <input type="hidden" name="action" value="update" />
 <input type="hidden" name="page" value="selfshortener-edit" />
 <input type="hidden" id="id" name="id" value="<?php
    echo esc_attr($link['id']) ?>" />
 <input class="button-primary" type="submit" name="submit" value="<?php
    echo esc_attr($link['id'] > 0
	? __("Update", "selfshortener")
	: __("Register", "selfshortener")) ?>" />
<?php
    if ($link['id'] > 0) {
	if ($link['enabled']) { ?>
 <input class="button-secondary" type="submit" name="delete" value="<?php
    _e("Delete", 'selfshortener') ?>" />
<?php   } else { ?>
 <input class="button-secondary" type="submit" name="restore" value="<?php
    _e("Restore", 'selfshortener') ?>" />
<?php   } ?>
<?php } ?>
</p>

</form>

<?php
}

function selfshortener_get_trunced($s, $length=60) {
    if (strlen($s) <= $length) return esc_html($s);
    return ("<span title=\"" . esc_html($s) . "\">"
	    . esc_html(substr($s, 0, $length)) . "..."
	    . "</span>");
}

function selfshortener_manage_links() {
    global $wpdb;

    if (!current_user_can('edit_posts')) {
	return;
    }

    $keyword = $_REQUEST['keyword'];
    $paged = intval($_REQUEST['paged']);
    $show_delete = $_REQUEST['show_delete'];
    if ($paged < 1) $paged = 1;
    $per_page = 30;

    $table = $wpdb->prefix . SELFSHORTENER_URL_TABLE;
    $user_table = $wpdb->prefix . "users";

    $cond = "FROM $table t LEFT JOIN $user_table u "
	. "ON (u.ID = t.author) "
	. "WHERE t.enabled " . ($show_delete ? "<>" : "=") . " TRUE ";
    if ($keyword) {
	$cond .= $wpdb->prepare("AND ukey LIKE %s OR url LIKE %s ",
				like_escape($keyword) . "%",
				"%" . like_escape($keyword) . "%");
    }
    if (!current_user_can('edit_others_posts')) {
	$cond .= $wpdb->prepare("AND author = %d ", $GLOBALS['user_ID']);
    }

    $count = $wpdb->get_var("SELECT COUNT(*) " . $cond);
    $total = ceil($count / $per_page);
    $page_links = paginate_links( array(
	'base' => add_query_arg(
	    array('paged' => '%#%', 'keyword' => $keyword)),
	'format' => '',
	'prev_text' => __('&laquo;'),
	'next_text' => __('&raquo;'),
	'total' => $total,
	'current' => $paged,
    ));

    $sql = "SELECT t.id, t.author, "
	. "COALESCE(u.display_name, u.user_login, t.author) AS author_name, "
	. "t.ukey, t.url "
	. $cond . "ORDER BY createtime DESC "
	. "LIMIT " . $per_page . " OFFSET " . ($per_page * ($paged - 1));
    $links = $wpdb->get_results($sql);

?>
<h2><?php _e('Manage Links', 'selfshortener') ?></h2>

<div class="tablenav">
<form method="get" action="admin.php">
 <input type="hidden" name="page" value="selfshortener-manage" />
 <input type="text" name="keyword" style="width: 50%" value="<?php
    echo htmlspecialchars($keyword) ?>" />
 <input type="submit" name="search" value="<?php _e('Search', 'selfshortener') ?>" />
 <input type="checkbox" name="show_delete" value="t"<?php
    echo ($show_delete ? " checked" : "") ?> /><?php
    _e("Only Deleted", "selfshortener") ?>
 &nbsp; 
 <a href="<?php echo admin_url('admin.php'),
	'?page=selfshortener-manage"' ?>"><?php
    _e('Clear', 'selfshortener') ?></a>
 &nbsp; 
 <a href="<?php echo admin_url('admin.php'),
	'?page=selfshortener-edit"' ?>"><?php
    _e('New Link', 'selfshortener') ?></a>
</form>
<?php if ($page_links) {
    $page_links_text = sprintf('<span class="displaying-num">'
		. __('Displaying %s&#8211;%s of %s', 'selfshortener')
		. '</span>%s',
	number_format_i18n(($paged - 1) * $per_page + 1),
	number_format_i18n(min($paged * $per_page, $count)),
	number_format_i18n($count), $page_links); ?>
<div class="tablenav-pages"><?php echo $page_links_text ?></div>
<?php } ?>

<?php if ($links) { ?>
<table class="widefat post fixed" cellspacing="0">
<col style="width:10ex" /><col /><col style="width:25%" />
<col style="width:20ex" />
<thead>
 <tr>
  <th><?php _e("Key", "selfshortener") ?></th>
  <th><?php _e("URL", "selfshortener") ?></th>
  <th><?php _e("Short URL", "selfshortener") ?></th>
  <th><?php _e("Author", "selfshortener") ?></th>
 </tr>
</thead>
<tbody>
<?php foreach ($links as $link) {
    $surl = selfshortener_get_link_url($link->ukey); ?>
 <tr>
  <td><a href="<?php echo add_query_arg(
    array('id' => $link->id, 'page' => 'selfshortener-edit',
	  'keyword' => $keyword, 'paged' => $paged)) ?>"><?php
    echo esc_html($link->ukey) ?></a></td>
  <td><?php echo selfshortener_get_trunced($link->url) ?></td>
  <td><a href="<?php echo esc_attr($surl) ?>" target="_blank"><?php
    echo esc_html($surl) ?></a></td>
  <td><?php echo esc_html($link->author_name) ?></td>
 </tr>
<?php } ?>
</tbody>
</table>

<?php } ?>

<?php if ($page_links) { ?>
<div class="tablenav-pages"><?php echo $page_links_text ?></div>
<?php } ?>

</div>

<?php
}

function selfshortener_get_request_param($name) {
    return isset($_REQUEST[$name]) ? $_REQUEST[$name] : null;
}

function selfshortener_options() {
    if (!current_user_can('manage_options')) {
	return;
    }

    $errors = array();
    $prefix = selfshortener_get_prefix();
    $key_length = selfshortener_get_key_length();
    if (!empty($_REQUEST['action']) && $_REQUEST['action'] == 'update') {
	// check parameters and store

	$prefix = trim(selfshortener_get_request_param('selfshortener_prefix'));
	$key_length =
	    trim(selfshortener_get_request_param('selfshortener_key_length'));


	if ($prefix == "") {
	    $errors["prefix"] = __("Prefix path required", "selfshortener");
	} else if (preg_match('!([^-0-9A-Za-z_./]+)!', $prefix, $m)) {
	    $errors["prefix"] =
		sprintf(__("Invalid character: %s", "selfshortener"),
			esc_html($m[1]));
	} else {
	    $prefix = preg_replace('!/{2,}!', '/', $prefix);
	    if (!preg_match('!^/!', $prefix)) {
		$prefix = '/' . $prefix;
	    }
	    if (!preg_match('!/$!', $prefix)) {
		$prefix .= '/';
	    }
	}

	if ($key_length == "") {
	    $errors["key_length"] = __("Link Key Length required");
	} else if (!preg_match('/^[0-9]{1,5}$/', $key_length)) {
	    $errors["key_length"] =
		__("Invalid Link Key Length format", 'selfshortener');
	} else {
	    $key_length = intval($key_length);
	    if ($key_length < 1) {
		$errors["key_length"] =
		    __("Link Key Length too small", 'selfshortener');
	    } else if ($key_length > SELFSHORTENER_MAX_KEY_LENGTH) {
		$errors["key_length"] =
		    __("Link Key Length too large", 'selfshortener');
	    }
	}

	if (count($errors) == 0) {
	    selfshortener_set_option("prefix", $prefix);
	    selfshortener_set_option("key_length", $key_length);
	}
    }

?>
<h2><?php _e('Self Shortener Setting', 'selfshortener') ?></h2>

<form action="<?php echo admin_url('admin.php'),
	"?page=selfshortener-options" ?>" method="post">
 <table class="form-table">
 <tr valign="top">
  <th scope="row"><?php _e('Prefix path', 'selfshortener') ?></th>
  <td><input type="text" name="selfshortener_prefix" value="<?php
    echo esc_attr($prefix) ?>" />
   <span class="description"><?php _e('Prefix path (directory) to use for Self Shortener.', 'selfshortener') ?></span>
<?php if (!empty($errors['prefix'])) { ?>
   <br /><span class="error"><?php echo $errors['prefix'] ?></span>
<?php } ?>
   <br />
   <span class="description"><?php _e('You should check your .htaccess to confirm the URL under the specified path is correctly handled by WordPress.', 'selfshortener') ?></span>
  </td>
 </tr>
 <tr valign="top">
  <th scope="row"><?php _e('Link Key Length', 'selfshortener') ?></th>
  <td><input type="text" name="selfshortener_key_length" class="small-text" maxlength="5" value="<?php
    echo esc_attr($key_length) ?>" />
   <span class="description"><?php
    echo esc_html(sprintf(__('Default length of auto-generate link key. (%d-%d)', 'selfshortener'), 2, SELFSHORTENER_MAX_KEY_LENGTH)) ?></span>
<?php if (!empty($errors['key_length'])) { ?>
   <br /><span class="error"><?php echo $errors['key_length'] ?></span>
<?php } ?>
  </td>
 </tr>
 </table>
 <p class="submit">
  <input type="submit" class="button-primary" value="<?php _e('Save Changes', 'selfshortener') ?>" />
  <input type="hidden" name="action" value="update" />
  <input type="hidden" name="page" value="selfshortener-options" />
 </p>
</form>
<?php
}


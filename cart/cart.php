<?php
/**
 * Name: cart
 * Description: Core cart utilities for orders and payments
 * Version: 0.1
 * Depends: Core
 * Recommends: None
 * Category: ECommerce
 * Author: Matthew Dent <dentm42@dm42.net>
 * Maintainer: Matthew Dent <dentm42@dm42.net>
 */

/* Architecture notes:
 *    The cart addon adds shopping cart, fulfillment
 *    and payment processing capabilities to Hubzilla in a modular
 *    manner.  Each component (cart, fulfillment, payment) can be
 *    extended by additional addons using HOOKS
 * 
 */

 /* DEVNOTES
  *  App::$config['system']['addon'] contains a comma-separated list of names
         of plugins/addons which are used on this system.
  */


$cart_version = 0.1;
load_config("cart");

function cart_maybeunjson ($value) {

    if (is_array($value)) {
        return $value;
    }

    if ($value!=null) {
        $decoded=json_decode($value,true);
    } else {
        return null;
    }

    if (json_last_error() == JSON_ERROR_NONE) {
        return ($decoded);
    } else {
        return ($value);
    }
}
    
function cart_maybejson ($value,$options=0) {

    if ($value!=null) {
        if (!is_array($value)) {
            $decoded=json_decode($value,true);
        }
    } else {
        return null;
    }

    if (is_array($value) || json_last_error() != JSON_ERROR_NONE) {
		$encoded = json_encode($value,$options);
        return ($encoded);
    } else {
        return ($value);
    }
}

function cart_dbCleanup () {
	$dbverconfig = get_config("dm42cart","dbver");

	$dbver = $dbverconfig ? $dbverconfig : 0;

	$dbsql = Array (
	    1 => Array (
	      	"DROP TABLE IF EXISTS `dm42cart_orders;`",
			"DROP TABLE IF EXISTS `dm42cart_orderitems`;"
	    )
    );
    $sql = $dbsql[$dbver];
	foreach ($sql as $query) {
		$r = q($query);
		if (!$r) {
			notice ('[cart] Error running dbCleanup.');
			logger ('[cart] Error running dbCleanup. sql query: '.$query);
			return UPDATE_FAILED;
		}
		
	}
	notice ('[cart] dbCleanup successful.');
	logger ('[cart] dbCleanup successful.');
	cart_delsysconfig("dbver");
	return UPDATE_SUCCESS;
}

function cart_dbUpgrade () {
	$dbverconfig = cart_getsysconfig("dbver");

	$dbver = $dbverconfig ? $dbverconfig : 0;

	$dbsql = Array (
		1 => Array (
			"DROP TABLE IF EXISTS `dm42cart_orders;`",
			// order_currency = ISO4217 currency alphabetic code
			// buyer_altid = email address or other unique identifier for the buyer
			"CREATE TABLE `dm42cart_orders` (
				`id` int(10) UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
				`seller_channel` varchar(255),
				`buyer_xchan` varchar(255),
				`buyer_altid` varchar(255),
				`order_hash` varchar(255) NOT NULL,
				`order_date` datetime,
				`order_currency` default 'USD',
				`order_meta` text,
				UNIQUE (order_hash)
				) ENGINE = MYISAM DEFAULT CHARSET=utf8;
			",
			"alter table `dm42cart_orders` add index (`seller_channel`)",
			"DROP TABLE IF EXISTS `dm42cart_orderitems`;",
			"CREATE TABLE dm42cart_orderitems (
				`id` int(10) UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
				`order_hash` varchar(255),
				`item_lastupdate` datetime,
				`item_type` varchar(25),
				`item_sku` varchar(25),
				`item_qty` int(10) UNSIGNED,
				`item_price` numeric(7,2),
				`item_tax_rate` numeric (4,4),
				`item_confirmed` bool default false,
				`item_paid` bool default false,
				`item_fulfilled` bool default false,
				`item_exception` bool default false,
				`item_meta` text
				) ENGINE = MYISAM DEFAULT CHARSET=utf8;
			",
			"alter table `dm42cart_orderitems` add index (`order_hash`)"
		    )
	);

   	foreach ($dbsql as $ver => $sql) {
		if ($dbver < $ver) {
			continue;
		}
		foreach ($sql as $query) {
			$r = q($query);
			if (!$r) {
				notice ('[cart] Error running dbUpgrade.');
				logger ('[cart] Error running dbUpgrade. sql query: '.$query);
				return UPDATE_FAILED;
			}
		}
		cart_setsysconfig("dbver".$ver);
	}
	return UPDATE_SUCCESS;
}

function cart_checkver() {
	global $cart_version;
	if (cart_getsysconfig("appver") == $cart_version) {
		return true;
	}
	
	cart_setsysconfig("status","version-mismatch");
	return false;
}

function cart_getsysconfig($param) {
	return get_config("cart",$param);
}

function cart_setsysconfig($param,$val) {
		return set_config("cart",$param,$val);
}

function cart_getcartconfig($param) {
	if (! local_channel()) {
		return null;
	}
	return get_pconfig(local_channel(),"cart",$param);
}

function cart_delcartconfig($param,$val) {
	if (! local_channel()) {
		return null;
	}

	return del_pconfig(local_channel(),"cart",$param);
}

function cart_setcartconfig($param,$val) {
		if (! local_channel()) {
		return null;
	}

	return set_pconfig(local_channel(),"cart",$param);
}

function cart_install() {
	if (cart_dbUpgrade () == UPDATE_FAILED) {
		cart_setsysconfig("status","install error");
		return;
	}
	cart_setsysconfig("appver",$cart_version);
	cart_setsysconfig("status","ready");
	cart_setsysconfig("dropTablesOnUninstall",0);
}
	
function cart_uninstall() {
	$dropTablesOnUninstall = intval(cart_getsysconfig("dropTablesOnUninstall"));
	if ($dropTablesOnUinstall === 1) {
		cart_dbCleanup ();
	}
	
	cart_delsysconfig("appver");
	notice ('[cart] Uninstalled.');
	logger ('[cart] Uninstalled.');
	cart_setsysconfig("status","uninstalled");
}

function cart_load(){
	/*
	register_hook('construct_page', 'addon/cart/cart.php', 'cart_construct_page');
	register_hook('feature_settings', 'addon/cart/cart.php', 'cart_settings');
	register_hook('feature_settings_post', 'addon/cart/cart.php', 'cart_settings_post');
	*/

}


function cart_unload(){
	/*
	unregister_hook('construct_page', 'addon/cart/cart.php', 'cart_construct_page');
	unregister_hook('feature_settings', 'addon/cart/cart.php', 'cart_settings');
	unregister_hook('feature_settings_post', 'addon/cart/cart.php', 'cart_settings_post');
	*/
}

/*
 *  HOOKS:
 * 		dm42cart_order_before_additem
 * 		dm42cart_order_before_additem_{itemtype}
 * 		dm42cart_order_additem
 * 		dm42cart_order_additem_{itemtype}
 * 		dm42cart_order_after_additem
 * 		dm42cart_order_after_additem_{itemtype}
 * 		dm42cart_order_before_delitem
 * 		dm42cart_order_before_delitem_{itemtype}
 * 		dm42cart_order_delitem
 * 		dm42cart_order_delitem_{itemtype}
 * 		dm42cart_order_after_delitem
 * 		dm42cart_order_after_delitem_{itemtype}
 *      dm42cart_before_checkout
 * 		dm42cart_before_checkout_{itemtype}
 *      dm42cart_checkout
 *      dm42cart_after_checkout
 * 		dm42cart_after_checkout_{itemtype}
 * 		dm42cart_orderpaid
 * 		dm42cart_orderpaid_{itemtype}
 * 		dm42cart_fulfill_item
 * 		dm42cart_fulfill_item_{itemtype}
 *      dm42cart_payprocess_register
 * 			adds ["{uniqueslug}"]="payprocess_hookname" to passed array
 *		
 *
 */


function cart_construct_page(&$a, &$b){
	if(! local_channel())
		return;

	$some_setting = get_pconfig(local_channel(), 'cart','some_setting');

	// Whatever you put in settings, will show up on the left nav of your pages.
	$b['layout']['region_aside'] .= '<div>' . htmlentities($some_setting) .  '</div>';

}



function cart_settings_post($a,$s) {
	if(! local_channel())
		return;

	set_pconfig( local_channel(), 'cart', 'some_setting', $_POST['some_setting'] );

}

function cart_settings(&$a,&$s) {
	$id = local_channel();
	if (! $id)
		return;

	$some_setting = get_pconfig( $id, 'cart', 'some_setting');

	$sc = replace_macros(get_markup_template('field_input.tpl'), array(
				     '$field'	=> array('some_setting', t('Some setting'), 
							 $some_setting, 
							 t('A setting'))));
	$s .= replace_macros(get_markup_template('generic_addon_settings.tpl'), array(
				     '$addon' 	=> array('cart',
							 t('Skeleton Settings'), '', 
							 t('Submit')),
				     '$content'	=> $sc));

}

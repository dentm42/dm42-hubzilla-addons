<?php

function cart_myshop_load(){
	Zotlabs\Extend\Hook::register('cart_main_myshop', 'addon/cart/cart.php', 'cart_construct_page');
}

function cart_myshop_unload(){
	Zotlabs\Extend\Hook::unregister('cart_main_myshop', 'addon/cart/cart.php', 'cart_construct_page');
}

/* FUTURE/TODO

function cart_myshop_searchparams ($search) {

  $keys = Array (
		"order_hash"=>Array("key"=>"order_hash","cast"=>"'%s'","escfunc"=>"dbesc"),

		"item_desc"=>Array("key"=>"item_desc","cast"=>"'%s'","escfunc"=>"dbesc"),
		"item_type"=>Array("key"=>"item_type","cast"=>"'%s'","escfunc"=>"dbesc"),
		"item_sku"=>Array("key"=>"item_sku","cast"=>"'%s'","escfunc"=>"dbesc"),
		"item_qty"=>Array("key"=>"item_qty","cast"=>"%d","escfunc"=>"intval"),
		"item_price"=>Array("key"=>"item_price","cast"=>"%f","escfunc"=>"floatval"),
		"item_tax_rate"=>Array("key"=>"item_tax_rate","cast"=>"%f","escfunc"=>"floatval"),
		"item_meta"=>Array("key"=>"item_meta","cast"=>"'%s'","escfunc"=>"dbesc"),
		);

	$colnames = '';
	$valuecasts = '';
	$params = Array();
	$count=0;
	foreach ($keys as $key=>$cast) {
		if (isset($search[$key])) {
			$colnames .= ($count > 0) ? "," : '';
			$colnames .= $cast["key"];
			$valuecasts .= ($count > 0) ? "," : '';
			$valuecasts .= $cast["cast"];
                        $escfunc = $cast["escfunc"];
                        logger ("[cart] escfunc = ".$escfunc);
			$params[] = $escfunc($item[$key]);
			$count++;
		}
	}
}
*/

function cart_myshop_allorders ($search=null,$limit=100,$offset=1) {
/**
  * search = Array of search terms:  //NOT YET IMPLEMENTED
  *   [""]
***/
  $seller_hash=get_observer_hash();
  $r=q("select unique(cart_order.order_hash) from cart_order,cart_orderitems
        where cart_order.order_hash = cart_orderitems.orderhash and
        seller_channel = '%s'
        limit=%i offset=%i",
      dbesc($seller_hash),
      intval($limit), intval($offset));

  if (!$r) {return Array();}

  foreach ($r as $order) {
    $orders[] = cart_loadorder($order["ohash"]);
  }
  return $orders;
}

function cart_myshop_openorders ($search=null,$limit=100,$offset=1) {
/**
  * search = Array of search terms:
  *   [""]
***/
  $seller_hash=get_observer_hash();
  $r=q("select unique(cart_order.order_hash) from cart_order,cart_orderitems
        where cart_order.order_hash = cart_orderitems.orderhash and
        seller_channel = '%s' and cart_orderitems.item_fulfilled is NULL
        and cart_orderitems.item_confirmed is not NULL
        limit=%i offset=%i",
      dbesc($seller_hash),
      intval($limit), intval($offset));

  if (!$r) {return Array();}

  foreach ($r as $order) {
    $orders[] = cart_loadorder($order["ohash"]);
  }
  return $orders;
}

function cart_myshop_closedorders ($search=null,$limit=100,$offset=1) {

  $seller_hash=get_observer_hash();
  $r=q("select order_hash from cart_orders where
        seller_channel = '%s' and
        cart_orders.order_hash not in (select order_hash from cart_orderitems
        where item_fulfilled is not null)
        limit=%i offset=%i",
      dbesc($seller_hash),
      intval($limit), intval($offset));

  if (!$r) {return Array();}

  foreach ($r as $order) {
    $orders[$order["order_hash"]] = cart_loadorder($order["order_hash"]);
  }
  return $orders;
}

function cart_myshop_pagecontent(&$pagecontent) {

  $sellernick = argv(1);
  $seller = channelx_by_nick($sellernick);

  if(! $seller) {
        notice( t('Invalid channel') . EOL);
        goaway('/' . argv(0));
  }

  $observer_hash = get_observer_hash();
  $is_seller = ((local_channel()) && (local_channel() == \App::$profile['profile_uid']) ? true : false);

  if (! $is_seller) {
    $pagecontent = "<center>";
    $pagecontent .= "<h1>Error</h1>";
    $pagecontent .= "<h4>Access denied.</h4>";
  }

  $pagecontent = '';
  //cart_myshop_menu();

  if ((argc() >= 5) && (argv(3) == 'order')) {

    $orderhash = argv(4);
    $orderhash = preg_replace('/[^a-z0-9]/','',$orderhash);
		$order = cart_loadorder($orderhash);
    $channel=\App::get_channel();
		$channel_hash=$channel["channel_hash"];
		if (!$order || $order["seller_channel"]!=$channel_hash) {
			return "<h1>".t("Order Not Found")."</h1>";
		}
  }

}

function cart_main_myshop (&$page) {

  $page=cart_myshop_pagecontent($page);
}

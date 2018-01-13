<?php

function cart_myshop_load(){
	Zotlabs\Extend\Hook::register('cart_main_myshop', 'addon/cart/cart.php', 'cart_construct_page');
}

function cart_myshop_unload(){
	Zotlabs\Extend\Hook::unregister('cart_main_myshop', 'addon/cart/cart.php', 'cart_construct_page');
}

function cart_myshop_openorders ($limit=100,$offset=1) {

  $seller_hash=get_observer_hash();
  $r=q("select unique(cart_order.order_hash) as ohash from cart_order,cart_orderitems
        where cart_order.order_hash = cart_orderitems.orderhash and
        seller_channel = '%s' and cart_orderitems.item_fulfilled=false
        limit=%i offset=%i",
      dbesc($seller_hash),
      intval($limit), intval($offset));

  if (!$r) {return Array();}

  foreach ($r as $order) {
    $orders[] = cart_loadorder($order["ohash"]);
  }
  return $orders;
}

function cart_myshop_closedorders ($limit=100,$offset=1) {

  $seller_hash=get_observer_hash();
  $r=q("select order_hash as ohash from cart_order where
        seller_channel = '%s' and
        ohash not in (select order_hash from cart_orderitems
        where item_fulfilled item_fulfilled=false)
        limit=%i offset=%i",
      dbesc($seller_hash),
      intval($limit), intval($offset));

  if (!$r) {return Array();}

  foreach ($r as $order) {
    $orders[] = cart_loadorder($order["ohash"]);
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

}

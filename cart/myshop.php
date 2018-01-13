<?php


function cart_load(){
	Zotlabs\Extend\Hook::register('cart_main_myshop', 'addon/cart/cart.php', 'cart_construct_page');

}

function cart_unload(){
	//Zotlabs\Extend\Hook::unregister('cart_post_add_item','addon/cart/cart.php','cart_post_add_item');

}

function cart_myshop_openorders () {

}

function cart_myshop_closedorders () {

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

<?php
function cart_post_manual_checkout_confirm () {

	$nick = cart_getnick();

	$orderhash = cart_getorderhash(false);

    if ($_POST["orderhash"] != $orderhash) {
        notice (t('Error: order mismatch. Please try again.') . EOL );
        goaway(z_root() . '/cart/' . $nick . '/checkout/start');
	}

	$order = cart_loadorder($orderhash);
	cart_do_checkout ($order);
	cart_do_checkout_after ($order);
	//cart_do_fulfill ($order); //No auto fulfillment on manual payments.
  goaway(z_root() . '/cart/' . $nick . '/checkout/complete');
}

function cart_checkout_complete (&$hookdata) {


}
function cart_checkout_manual (&$hookdata) {

	$channel = \App::get_channel();
	$nick_xchann = $channel["channel_hash"];

	$manualpayments = get_pconfig($nick_xchann,'cart','enable_manual_payments');
	$manualpayments = isset($manualpayments) ? $manualpayments : false;

	if (!$manualpayments) {
		notice (t('Manual payments are not enabled.') . EOL );
		goaway(z_root() . '/cart/' . $nick . '/checkout/start');
	}

	$orderhash = cart_getorderhash(false);

	if (!$orderhash) {
		notice (t('Order not found.') . EOL );
		goaway(z_root() . '/cart/' . $nick . '/order');
	}

	$order = cart_loadorder($orderhash);
	$manualpayopts = get_pconfig(local_channel(),'cart','manual_payopts');
	$manualpayopts["order_hash"]=$orderhash;
	$order["payopts"]=$manualpayopts;
	$order["finishedtext"]=t("Finished");
	$order["finishedurl"]= z_root() . '/cart/' . $nick;
    $template = get_markup_template('basic_checkout_manual_confirm.tpl','addon/cart/');
	$display = replace_macros($template, $order);

	$hookdata["checkoutdisplay"] = $display;
}

function cart_paymentopts_register_manual (&$hookdata) {
	$manualpayments = get_pconfig(local_channel(),'cart','enable_manual_payments');
	$manualpayments = isset($manualpayments) ? $manualpayments : false;
        logger ("[cart] MANUAL PAYMENTS? ".print_r($manual_payments,true));
	if ($manualpayments) {
		$hookdata["manual"]=Array('title'=>'Manual Payment','html'=>"<b>Pay by Check, Money Order, or other manual payment method</b>");
	}
    return;
}

function cart_manualpayments_unload () {

    Zotlabs\Extend\Hook::unregister('cart_paymentopts','addon/cart/manual_payments.php','cart_paymentopts_register_manual');
    Zotlabs\Extend\Hook::unregister('cart_checkout_manual','addon/cart/manual_payments.php','cart_checkout_manual');

    }

function cart_manualpayments_load () {

    Zotlabs\Extend\Hook::register('cart_paymentopts','addon/cart/manual_payments.php','cart_paymentopts_register_manual');
    Zotlabs\Extend\Hook::register('cart_checkout_manual','addon/cart/manual_payments.php','cart_checkout_manual');

    }

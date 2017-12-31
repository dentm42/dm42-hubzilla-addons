<?php

/**
 * Name: cart
 * Description: Core cart utilities for orders and payments
 * Version: 0.5
 * Author: Matthew Dent <dentm42@dm42.net>
 * MinVersion: 2.8
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


$cart_version = 0.5;
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
	      	"DROP TABLE IF EXISTS cart_orders",
			"DROP TABLE IF EXISTS cart_orderitems"
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
	logger ('[cart] get sysconfig.');

	$dbver = $dbverconfig ? $dbverconfig : 0;
	notice ('[cart] current dbver = '.$dbver.'.');

	$dbsql = Array (
		1 => Array (
			"DROP TABLE IF EXISTS cart_orders",
			// order_currency = ISO4217 currency alphabetic code
			// buyer_altid = email address or other unique identifier for the buyer
			"CREATE TABLE `cart_orders` (
				`id` int(10) UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
				`seller_channel` varchar(255),
				`buyer_xchan` varchar(255),
				`buyer_altid` varchar(255),
				`order_hash` varchar(255) NOT NULL,
				`order_expires` datetime,
				`order_checkedout` datetime,
				`order_paid` datetime,
				`order_currency` varchar(10) default 'USD',
				`order_meta` text,
				UNIQUE (order_hash)
				) ENGINE = MYISAM DEFAULT CHARSET=utf8;
			",
			"alter table `cart_orders` add index (`seller_channel`)",
			"DROP TABLE IF EXISTS cart_orderitems",
			"CREATE TABLE cart_orderitems (
				`id` int(10) UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
				`order_hash` varchar(255),
				`item_lastupdate` datetime,
				`item_type` varchar(25),
				`item_sku` varchar(25),
				`item_desc` varchar(255),
				`item_qty` int(10) UNSIGNED,
				`item_price` numeric(7,2),
				`item_tax_rate` numeric (4,4),
				`item_confirmed` bool default false,
				`item_fulfilled` bool default false,
				`item_exception` bool default false,
				`item_meta` text
				) ENGINE = MYISAM DEFAULT CHARSET=utf8;
			",
			"alter table `cart_orderitems` add index (`order_hash`)"
		    )
	);

   	foreach ($dbsql as $ver => $sql) {
		if ($ver < $dbver) {
			continue;
		}
		foreach ($sql as $query) {
	                logger ('[cart] dbSetup:'.$query);
			$r = q($query);
			if (!$r) {
				notice ('[cart] Error running dbUpgrade.');
				logger ('[cart] Error running dbUpgrade. sql query: '.$query);
				return UPDATE_FAILED;
			}
		}
		cart_setsysconfig("dbver".$ver);
	}
	notice ('[cart] dbUpgrade to ('.$ver.') Successful.');
	return UPDATE_SUCCESS;
}

function cart_loadorder ($orderhash) {
	$r = q ("select * from cart_orders where order_hash = '%s' LIMIT 1",dbesc($orderhash));
	if (!$r) {
		return Array("order"=>null,"items"=>null);
	}

	$order = $r[0];
	$order["order_meta"]=cart_maybeunjson($order["order_meta"]);
	
	$r = q ("select * from cart_orderitems where order_hash = '%s'",dbesc($orderhash));
        logger ("[cart] Cart Has No Items orderhash = ".$orderhash);
	if (!$r) {
                logger ("[cart] Cart Has No Items");
		return Array("order"=>$order,"items"=>null);
	}
	$items=Array();
	foreach ($r as $key=>$iteminfo) {
		$items[$key]=$iteminfo;
		$items[$key]["extended"]=$iteminfo["item_qty"]*$iteminfo["item_price"];
		$items[$key]["itemtax"]=0;
                $ordertaxtotal=$ordertaxtotal + $items[$key]["itemtax"];
                $ordersubtotal=$ordersubtotal + $items[$key]["extended"];
	}
        $ordertotal=$ordertaxtotal + $ordersubtotal;
	$order["items"]=$items;
        $order["totals"]["Subtotal"]=$ordersubtotal;
        $order["totals"]["Tax"]=$ordersubtotal;
        $order["totals"]["OrderTotal"]=$ordertotal;
        $hookdata=$order;
	call_hooks("cart_calc_totals",$hookdata);
        logger ("[cart] cart_loadorder order: ".print_r($hookdata,true));
	return $hookdata;
}

function cart_getorderhash ($create=false) {
	$orderhash = isset($_SESSION["cart_order_hash"]) ? $_SESSION["cart_order_hash"] : null;
	$observerhash = get_observer_hash();
	if ($observerhash === '') { $observerhash = null; }
	$cartemail = isset($_SESSION["cart_email_addy"]) ? $_SESSION["cart_email_addy"] : null;
	
	if ($orderhash) {
                logger ("orderhash in SESSION = ".$orderhash);
		$r = q("select * from cart_orders where order_hash = '%s' limit 1",dbesc($orderhash));
		if (!$r) {
			$orderhash=null;
		} else {
		    $order = $r[0];

                    $orderhash = $order["order_hash"];

		    if ($order["buyer_xchan"]!=$observerhash) {
			$orderhash=null;
		    }

		    if ($order["order_checkedout"]!=null) {
			$orderhash=null;
		    }
               }
	} else {
               logger ("orderhash not in SESSION - search db");
               $r = q("select * from cart_orders where buyer_xchan = '%s' and order_checkedout is null limit 1",dbesc($observerhash));

               if (!$r) {
		    $orderhash=null;
                    logger ("no matching orderhash in db");
               } else {
		    $order = $r[0];

                    $orderhash = $order["order_hash"];

		    if ($order["buyer_xchan"]!=$observerhash) {
			$orderhash=null;
		    }

		    if ($order["order_checkedout"]!=null) {
			$orderhash=null;
		    }
               }

        }

	if (!$orderhash && $create === true) {
		$channel=\App::get_channel();
		$channel_hash=$channel["channel_hash"];
		$orderhash=hash('whirlpool',microtime().$observerhash.$channel_hash);
		q("insert into cart_orders (seller_channel,buyer_xchan,order_hash) values ('%s', '%s', '%s')",
				dbesc($channel_hash),dbesc($observerhash),dbesc($orderhash));
		
		$_SESSION["cart_order_hash"]=$orderhash;
	}

	return $orderhash;
	
}

function cart_additem_hook (&$hookdata) {

	$order=$hookdata["order"];
	$item=$hookdata["item"];
        $item["order_hash"] = $order["order_hash"];
	if (isset($item["item_meta"])) {
		$item["item_meta"] = cart_maybejson($item["item_meta"]);
	}

	logger("[cart] AddItem Hookdata: ".print_r($hookdata,true));
	logger("[cart] AddItem: ".print_r($item,true));
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
		if (isset($item[$key])) {
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

	$sql = "insert into cart_orderitems (".$colnames.") values (".$valuecasts.")";
	array_unshift($params,$sql);	
	logger("[cart] insert item call q params: ".print_r($params,true));
	$r=call_user_func_array('q', $params);
        logger('[cart] post insert r = '.print_r($r,true));
}
	
//function cart_do_additem (array $iteminfo,&$c) {
function cart_do_additem (&$hookdata) {
	
        $startcontent = $hookdata["content"];
	$iteminfo=$hookdata["iteminfo"];
	$cart_itemtypes = cart_maybeunjson(get_pconfig("cart_itemtypes"));
	$required = Array("item_sku","item_qty","item_desc","item_price");
	foreach ($required as $key) {
		if (!array_key_exists($key,$iteminfo)) {
			$hookdata["content"]=$startcontent;
			$hookdata["errorcontent"][]='';
			$hookdata["error"][]="[cart] Cannot add item, missing required parameter.";
			return;
		}
	}
	$order=cart_loadorder(cart_getorderhash(true));

	$itemtype = isset($iteminfo["item_type"]) ? $iteminfo["item_type"] : null;
	
	if ($itemtype && !array_has_key($cart_itemtypes,$iteminfo['item_type'])) {
		unset ($iteminfo['item_type']);
	}

	$calldata = Array('order'=>$order,'item'=>$iteminfo);
	$itemtype = isset($calldata['item']['item_type']) ? $calldata['item']['item_type'] : null;

	if ($itemtype) {
		$itemtypehook='cart_order_before_additem_'.$itemtype;
		call_hooks($itemtypehook,$calldata);
		$hookdata["content"] .= isset($calldata["content"]) ? $calldata["content"] :'';
		unset($calldata["content"]);
		if (isset($calldata["error"]) && $calldata["error"]!=null) {
			$hookdata["content"]=$startcontent;
			$hookdata["errorcontent"][]=isset($calldata["errorcontent"]) ? $calldata["errorcontent"] : null;
			$hookdata["error"][]=$calldata["error"];
			unset($calldata["error"]);
			return;
		}
	}
	
	if (!isset($calldata["item"])) { return; }
	call_hooks('cart_order_before_additem',$calldata);

	$hookdata["content"] .= isset($calldata["content"]) ? $calldata["content"] : '';
	unset($calldata["content"]);
	if (isset($calldata["error"]) && $calldata["error"]!=null) {
		$hookdata["content"]=$startcontent;
		$hookdata["errorcontent"][]=isset($calldata["errorcontent"]) ? $calldata["errorcontent"] : null;
		$hookdata["error"][]=$calldata["error"];
		unset($calldata["error"]);
		return;
	}

	if (!isset($calldata["item"])) { return; }

	if ($itemtype) {
		$itemtypehook='cart_order_additem_'.$itemtype;
		call_hooks($itemtypehook,$calldata);
		$hookdata["content"] .= isset($calldata["content"]) ? $calldata["content"] :'';
		unset($calldata["content"]);
		if (isset($calldata["error"]) && $calldata["error"]!=null) {
			$hookdata["content"]=$startcontent;
			$hookdata["errorcontent"][]=isset($calldata["errorcontent"]) ? $calldata["errorcontent"] : null;
			$hookdata["error"][]=$calldata["error"];
			unset($calldata["error"]);
		}
	}

	if (!isset($calldata["item"])) { return; }

	call_hooks('cart_order_additem',$calldata);
	$hookdata["content"] .= isset($calldata["content"]) ? $calldata["content"] :'';
	unset($calldata["content"]);
	if (isset($calldata["error"]) && $calldata["error"]!=null) {
		$hookdata["content"]=$startcontent;
		$hookdata["errorcontent"][]=isset($calldata["errorcontent"]) ? $calldata["errorcontent"] : null;
		$hookdata["error"][]=$calldata["error"];
		unset($calldata["error"]);
		return;
	}
	
	if ($itemtype) {
		$itemtypehook='cart_order_after_additem_'.$itemtype;
		call_hooks($itemtypehook,$calldata);
		$hookdata["content"] .= isset($calldata["content"]) ? $calldata["content"] : '';
		unset($calldata["content"]);
		if (isset($calldata["error"]) && $calldata["error"]!=null) {
			$hookdata["content"]=$startcontent;
			$hookdata["errorcontent"][]=isset($calldata["errorcontent"]) ? $calldata["errorcontent"] : null;
			$hookdata["error"][]=$calldata["error"];
			unset($calldata["error"]);
		}
	}		
	call_hooks('cart_order_after_additem',$calldata);
	$hookdata["content"] .= isset($calldata["content"]) ? $calldata["content"] : '';
	unset($calldata["content"]);
	if (isset($calldata["error"]) && $calldata["error"]!=null) {
		$hookdata["content"]=$startcontent;
		$hookdata["errorcontent"][]=isset($calldata["errorcontent"]) ? $calldata["errorcontent"] : null;
		$hookdata["error"][]=$calldata["error"];
		unset($calldata["error"]);
	}
}

function cart_getorder_meta ($orderhash=null) {
	$orderhash = $orderhash ? $orderhash : cart_getorderhash();
	
	if (!$orderhash) {
		return null;
	}

	$r=q("select order_meta from cart_order where order_hash = '%s'",
			dbesc($orderhash));

	if (!$r) {return Array();}
	$meta=$r[0]["order_meta"];
	return (cart_maybeunjson($meta));
}

function cart_getitem_meta ($itemid,$orderhash=null) {
	$orderhash = $orderhash ? $orderhash : cart_getorderhash();
	
	if (!$orderhash) {
		return null;
	}

	$r=q("select item_meta from cart_orderitems where order_hash = '%s' and id = %d",
			dbesc($orderhash),intval($itemid));

	if (!$r) {return Array();}
	$meta=$r[0]["item_meta"];
	return (cart_maybeunjson($meta));
}

function cart_updateorder_meta ($meta,$orderhash=null) {
	$orderhash = $orderhash ? $orderhash : cart_getorderhash();
	
	if (!$orderhash) {
		return null;
	}

	$storemeta = cart_maybejson($meta);
	
	$r=q("update cart_order set order_meta = '%s' where order_hash = '%s'",
			dbesc($storemeta),dbesc($orderhash),intval($itemid));

	return;
}

function cart_updateitem_meta ($itemid,$meta,$orderhash=null) {
	$orderhash = $orderhash ? $orderhash : cart_getorderhash();
	
	if (!$orderhash) {
		return null;
	}

	$storemeta = cart_maybejson($meta);
	
	$r=q("update order_items set item_meta = '%s' where order_hash = '%s' and id = %d",
			dbesc($storemeta),dbesc($orderhash),intval($itemid));

	return;
}

function cart_updateitem_hook (&$hookdata) {
	
	$order=$hookdata["order"];
	$item=$hookdata["item"];

	$string_components = Array ( "item_sku","item_desc" );
	$int_components = Array ( "item_qty" );
	$decimal_components = Array ("item_price","item_tax_rate");
	$bool_components = Array ("item_confirmed","item_fulfilled","item_exception");

	
	$params = Array();
	$dodel=false;
	if (isset($item["item_qty"]) && $item["item_qty"] == 0) {
		$sql = "delete from cart_orderitems ";
		$dodel=true;
	} else {
		$sql = "update cart_orderitems ";
		foreach ($item as $key=>$val) {
			$prepend = '';
			if (count($params) > 0) {
				$prepend = ',';
			}
			if (in_array($key,$string_components)) {
				$sql .= $prepend." `$key`"." = '%s' ";
				$params[] = dbesc($val);
			} else
			if (in_array($key,$int_components)) {
				$sql .= $prepend."`$key`"." = %d ";
				$params[] = intval($val);
			} else
			if (in_array($key,$decimal_components)) {
				$sql .= $prepend."`$key`"." = %d ";
				$params[] = floatval($val);
			} else
			if (in_array($key,$bool_components)) {
				$sql .= $prepend."`$key`"." = %d ";
				$params[] = intval($val);
			}
		}
	}

	if ($dodel || count ($params) >0) {
		$orderhash = cart_getorderhash(false);
		if (!$orderhash) {return;}
		$sql .= " where order_hash = '%s' and id = %d ";
		$params[] = dbesc($order["order_hash"]);
		$params[] = intval($item["id"]);

		array_unshift($params,$sql);
		$r=call_user_func_array('q', $params);
	}

	if (isset($item["item_meta"])) {
		cart_updateitem_meta ($item["id"],$item["item_meta"],$order["order_hash"]);
	}
}

function cart_do_updateitem (&$hookdata) {
	$iteminfo=$hookdata["iteminfo"];
	$required = Array("id");
	foreach ($required as $key) {
		if (!array_has_key($iteminfo,$key)) {
			$hookdata["errorcontent"][]="[cart] Cannot update item, missing $key.";
			$hookdata["error"][]=$calldata["error"];
			return;
		}
	}

	$orderhash = cart_getorderhash();
	if (!$orderhash) { return; }
	$order=cart_loadorder($orderhash);
	$startcontent=$hookdata["content"];

	$itemtype = isset($iteminfo["item_type"]) ? $iteminfo["item_type"] : null;
	if ($itemtype && !array_has_key($cart_itemtypes,$iteminfo['item_type'])) {
		unset ($iteminfo['item_type']);
	}

	$calldata = Array('order'=>$order,'item'=>$iteminfo);

	$itemtype = isset($calldata['item']['item_type']) ? $calldata['item']['item_type'] : null;

	if ($itemtype) {
		$itemtypehook='cart_order_before_updateitem_'.$itemtype;
		call_hooks($itemtypehook,$calldata);
		$hookdata["content"].= isset($calldata["content"]) ? $calldata["content"] : '';
		unset($calldata["content"]);
		if (isset($calldata["error"]) && $calldata["error"]!=null) {
			$hookdata["content"]=$startcontent;
			$hookdata["errorcontent"][]=isset($calldata["errorcontent"]) ? $calldata["errorcontent"] : null;
			$hookdata["error"][]=$calldata["error"];
			return;
		}
	}
	
	if (!isset($calldata["item"])) { return; }
	
	call_hooks('cart_order_before_updateitem',$calldata);
	$hookdata["content"].= isset($calldata["content"]) ? $calldata["content"] : '';
	unset($calldata["content"]);
	if (isset($calldata["error"]) && $calldata["error"]!=null) {
			$hookdata["content"]=$startcontent;
			$hookdata["errorcontent"][]=isset($calldata["errorcontent"]) ? $calldata["errorcontent"] : null;
			$hookdata["error"][]=$calldata["error"];
			return;
	}


	if (!isset($calldata["item"])) { return; }

	if ($itemtype) {
		$itemtypehook='cart_order_updateitem_'.$itemtype;
		call_hooks($itemtypehook,$calldata);
		$hookdata["content"].= isset($calldata["content"]) ? $calldata["content"] : '';
		unset($calldata["content"]);
		if (isset($calldata["error"]) && $calldata["error"]!=null) {
			$hookdata["content"]=$startcontent;
			$hookdata["errorcontent"][]=isset($calldata["errorcontent"]) ? $calldata["errorcontent"] : null;
			$hookdata["error"][]=$calldata["error"];
			unset($calldata["error"]);
		}
	}

	if (!isset($calldata["item"])) { return; }

	call_hooks('cart_order_updateitem',$calldata);
	$hookdata["content"].= isset($calldata["content"]) ? $calldata["content"] : '';
	unset($calldata["content"]);
	if (isset($calldata["error"]) && $calldata["error"]!=null) {
			$hookdata["content"]=$startcontent;
			$hookdata["errorcontent"][]=isset($calldata["errorcontent"]) ? $calldata["errorcontent"] : null;
			$hookdata["error"][]=$calldata["error"];
			unset($calldata["error"]);
	}
	
	if ($itemtype) {
		$itemtypehook='cart_order_after_updateitem_'.$itemtype;
		call_hooks($itemtypehook,$calldata);
		$hookdata["content"].= isset($calldata["content"]) ? $calldata["content"] : '';
		unset($calldata["content"]);
		if (isset($calldata["error"]) && $calldata["error"]!=null) {
			$hookdata["content"]=$startcontent;
			$hookdata["errorcontent"][]=isset($calldata["errorcontent"]) ? $calldata["errorcontent"] : null;
			$hookdata["error"][]=$calldata["error"];
			unset($calldata["error"]);
		}
	}		
	call_hooks('cart_order_after_updateitem',$calldata);
	$hookdata["content"].= isset($calldata["content"]) ? $calldata["content"] : '';
	unset($calldata["content"]);
	if (isset($calldata["error"]) && $calldata["error"]!=null) {
		$hookdata["content"]=$startcontent;
		$hookdata["errorcontent"][]=isset($calldata["errorcontent"]) ? $calldata["errorcontent"] : null;
		$hookdata["error"][]=$calldata["error"];
		unset($calldata["error"]);
	}
}

function cart_display_item (&$hookdata) {
	$item = $hookdata["item"];
	$hookdata["content"].=replace_macros(get_markup_template('cart_item_basic.tpl','addon/cart/'), array('$item'	=> $item ));
	
}

function cart_calc_totals(&$hookdata) {
	$orderhash=isset($hookdata["order"]["order_hash"]) ? $hookdata["order"]["order_hash"] : null;
	if (!$order_hash) {return;}
	$order=cart_loadorder($orderhash);
	$ordermeta=$order["order_meta"];
	$items=$order["items"];
	$subtotal=0;
	$taxtotal=0;
	$ordertotal=0;
	foreach ($items as $key=>$item) {
		$linetotal=$item["qty"]*$item["item_price"];
		$hookdata["order"]["items"][$key]["extended"]=$linetotal;
		
		$linetax=$linetotal * $item["item_tax_rate"];
		
		$subtotal = $subtotal + $linetotal;
		$taxtotal = $taxtotal + $linetax;
	}
	$ordertotal = $subtotal+$taxtotal;
	$ordermeta["totals"]["Tax"]=sprintf("%01.2f",$taxtotal);;
	$ordermeta["totals"]["Subtotal"]=sprintf("%01.2f",$subtotal);;
	$ordermeta["totals"]["OrderTotal"]=sprintf("%01.2f",$ordertotal);
	cart_update_ordermeta($ordermeta,$orderhash);
	$hookdata["order"]["order_meta"]["totals"]=$ordermeta["totals"];
}

function cart_display_totals(&$hookdata) {
	$orderhash=isset($hookdata["order"]["order_hash"]) ? $hookdata["order"]["order_hash"] : null;
	$order=cart_loadorder($orderhash);
	$ordermeta=$order["order_meta"];
	$totals=$ordermeta["totals"];
	$hookdata['content']=isset($hookdata['content']) ? $hookdata['content'] : '';
	$hookdata['content'].= "<div class='totals'>";	
	foreach ($totals as $totalname=>$total) {
		$hookdata['content'] .= "<div class='totaldesc'>".t($totalname)."</div>";
		$hookdata['content'] .= "<div class='total'>".$total." ".$order["order_currency"]."</div>";
	}
	$hookdata['content'].= "</div>";
}

function cart_do_display (&$hookdata) {
// *Note: No errors or error messages returned
	$orderhash=$hookdata["order"]["order_hash"];
	
	$order=cart_loadorder($orderhash);
	$calldata = Array("order"=>$order,"content"=>null);
	call_hooks('cart_display_before',$calldata);
	$hookdata["content"].= isset($calldata["content"]) ? $calldata["content"] : '';

	foreach ($order["items"] as $iteminfo) {	
		$itemtype = isset($iteminfo["item_type"]) ? $iteminfo["item_type"] : null;
		if ($itemtype && !array_has_key($cart_itemtypes,$iteminfo['item_type'])) {
			continue;
		}

		$calldata = Array('item'=>$iteminfo,'error'=>null,'content'=>null);
		$itemtype = isset($calldata['item']['item_type']) ? $calldata['item']['item_type'] : null;

		if ($itemtype) {
			$itemtypehook='cart_display_before_'.$itemtype;
			call_hooks($itemtypehook,$calldata);
			$hookdata["content"].= isset($calldata["content"]) ? $calldata["content"] : '';
			unset($calldata["content"]);
		}
		
		$calldata["content"]=null;
		
		call_hooks("cart_display_item",$calldata);
		$hookdata["content"].= isset($calldata["content"]) ? $calldata["content"] : '';
		unset($calldata["content"]);
		call_hooks("cart_display_item_after",$calldata);
		$hookdata["content"].= isset($calldata["content"]) ? $calldata["content"] : '';
		unset($calldata["content"]);
		
		if ($itemtype) {
			$itemtypehook='cart_display_after_'.$itemtype;
			$calldata["content"]=null;
			call_hooks($itemtypehook,$calldata);
			$hookdata["content"].= isset($calldata["content"]) ? $calldata["content"] : '';
			unset($calldata["content"]);
		}
	}

	$calldata = Array("orderhash"=>$orderhash,"content"=>null);
	call_hooks('cart_display_after',$calldata);
	$hookdata["content"].= $calldata["content"];
}

function cart_processor_manual (&$hookdata) {
	
}

function cart_checkout_hook(&$hookdata) {
	$orderhash = isset($data["order_hash"]) ? $data["order_hash"] : null;
	
	if (!$orderhash) {
		/*  No order given. */
		return;
	}

	$order=cart_loadorder($orderhash);

	if ($order["order_checkedout"] != null) {
		/* Order previously checked out */
		return;
	}

	q("update cart_orders set `order_checkedout`=NOW() where `order_hash`='%s'",dbesc($orderhash));
		
	return;
	}

function cart_do_checkout_before (&$hookdata) {

	if (isset($hookdata["error"]) && $hookdata["error"]!=null) {
		return;
	}
	
	$orderhash = isset($hookdata["order"]["order_hash"]) ? $hookdata["order"]["order_hash"] : cart_getorderhash();
	$hookdata["error"]=null;
	if (!$orderhash) {
		$hookdata["errorcontent"][]="";
		$hookdata["error"][]="No active order";
		return;
	}

	$order=cart_loadorder($orderhash);
	$error=null;
	$startcontent=$hookdata["content"];

	if ($order["order_checkedout"] != null) {
		$hookdata["errorcontent"][]="";
		$hookdata["error"]="Order previously checked out";
		logger ('[cart] Attempt to checkout_before already checked out cart (order id:'.$order["id"].')');
		return;
	}

	foreach ($order["items"] as $iteminfo) {	
		$itemtype = isset($iteminfo["item_type"]) ? $iteminfo["item_type"] : null;
		if ($itemtype && !array_has_key($cart_itemtypes,$iteminfo['item_type'])) {
			continue;
		}

		$calldata = Array('item'=>$iteminfo,'error'=>null,'content'=>null);

		if ($itemtype) {
			$itemtypehook='cart_before_checkout_'.$itemtype;
			call_hooks($itemtypehook,$calldata);
			$hookdata["content"] .= isset($calldata["content"]) ? $calldata["content"] : '';
			unset($calldata["content"]);
			if (isset($calldata["error"]) && $calldata["error"]!=null) {
				$hookdata["content"]=$startcontent;
				$hookdata["errorcontent"][]=isset($calldata["errorcontent"]) ? $calldata["errorcontent"] : null;
				$hookdata["error"][]=$calldata["error"];
				return;
			}
		}
	}

	if (!$error) {
		unset($calldata);
		$calldata=Array('order'=>$order,"error"=>null,"content"=>null);
		call_hooks('cart_before_checkout',$calldata);
		$hookdata["content"].=isset($calldata["content"]) ? $calldata["content"] : '';
		unset($calldata["content"]);
		if (isset($calldata["error"]) && $calldata["error"]!=null) {
			$hookdata["content"]=$startcontent;
			$hookdata["errorcontent"][]=isset($calldata["errorcontent"]) ? $calldata["errorcontent"] : null;
			$hookdata["error"][]=$calldata["error"];
			return;
		}
	}
}

function cart_do_checkout (&$hookdata) {

	if (isset($hookdata["error"]) && $hookdata["error"]!=null) {
		return;
	}
	
	$orderhash = isset($hookdata["order"]["order_hash"]) ? $hookdata["order"]["order_hash"] : cart_getorderhash();
	$hookdata["error"]=null;
	if (!$orderhash) {
		$hookdata["errorcontent"][]="";
		$hookdata["error"][]="No active order";
		return;
	}

	$order=cart_loadorder($orderhash);
	$error=null;

	if ($order["order_checkedout"] != null) {
		$hookdata["errorcontent"][]="";
		$hookdata["error"][]="Order previously checked out";
		logger ('[cart] Attempt to check out already checked out cart (order id:'.$order["id"].')');
		return;
	}

	$startcontent=$hookdata["content"];

	unset($calldata);
	$calldata=Array('order'=>$order,"error"=>null,"content"=>null);
	call_hooks('cart_checkout',$calldata);
	$hookdata["content"].=isset($calldata["content"]) ? $calldata["content"] : '';
	unset($calldata["content"]);
	if (isset($hookdata["error"]) && $hookdata["error"]!=null) {
		$hookdata["content"]=$startcontent;
		$hookdata["errorcontent"][]=isset($calldata["errorcontent"]) ? $calldata["errorcontent"] : null;
		$hookdata["error"][]=$calldata["error"];
		return;
	}

	return;
}

function cart_do_checkout_after (&$hookdata) {

	if (isset($hookdata["error"]) && $hookdata["error"]!=null) {
		return;
	}

	$orderhash = isset($hookdata["order"]["order_hash"]) ? $hookdata["order"]["order_hash"] : cart_getorderhash();
	$hookdata["error"]=null;
	if (!$orderhash) {
		$hookdata["errorcontent"][]="";
		$hookdata["error"][]="No active order";
		return;
	}

	$order=cart_loadorder($orderhash);
	$error=null;

	if ($order["order_checkedout"] != null) {
		$hookdata["errorcontent"][]="";
		$hookdata["error"][]="Order previously checked out";
		logger ('[cart] Attempt to check out already checked out cart (order id:'.$order["id"].')');
		return;
	}

	$startcontent=$hookdata["content"];

	foreach ($order["items"] as $iteminfo) {	
		$itemtype = isset($iteminfo["item_type"]) ? $iteminfo["item_type"] : null;
		if ($itemtype && !array_has_key($cart_itemtypes,$iteminfo['item_type'])) {
			continue;
		}
		$calldata = Array('item'=>$iteminfo,'content'=>null);
		$itemtype = isset($calldata['item']['item_type']) ? $calldata['item']['item_type'] : null;
		if ($itemtype) {
			$itemtypehook='cart_after_checkout_'.$itemtype;
			call_hooks($itemtypehook,$calldata);
			$hookdata["content"].=isset($calldata["content"]) ? $calldata["content"] : '';
			unset($calldata["content"]);
			if (isset($calldata["error"]) && $calldata["error"]!=null) {
				$hookdata["content"]=$startcontent;
				$hookdata["errorcontent"][]=isset($calldata["errorcontent"]) ? $calldata["errorcontent"] : null;
				$hookdata["error"][]=$calldata["error"];
				return;
			}
		}
		unset($calldata);
	}
	
	$calldata=Array('order'=>$order,"content"=>null);
	call_hooks('cart_after_checkout',$calldata);
	$data["content"].=isset($calldata["content"]) ? $calldata["content"] : '';
	unset($calldata["content"]);
	if (isset($calldata["error"]) && $calldata["error"]!=null) {
		$hookdata["content"]=$startcontent;
		$hookdata["errorcontent"][]=isset($calldata["errorcontent"]) ? $calldata["errorcontent"] : null;
		$hookdata["error"][]=$calldata["error"];
		return;
	}

	return;
}

function cart_orderpaid_hook (&$hookdata) {
	$items = $hookdata["order"]["items"];
	foreach ($items as $item) {
		q ("update cart_orderitems set `paid` = NOW() where order_hash = `%s` and id = %d",
				dbesc($hookdata["order"]["order_hash"]),
				intval($item["id"])
		);
	}
}

function cart_do_orderpaid (&$hookdata) {
	$orderhash=$hookdata["order"]["order_hash"];
	$order=cart_loadorder($orderhash);
	$startdata=isset($hookdata["content"]) ? $hookdata["content"] : null;
	foreach ($order["items"] as $iteminfo) {	
		$itemtype = isset($iteminfo["item_type"]) ? $iteminfo["item_type"] : null;
		if ($itemtype && !array_has_key($cart_itemtypes,$iteminfo['item_type'])) {
			continue;
		}

		$calldata = Array('item'=>$iteminfo,'error'=>null,'content'=>null);
		$itemtype = isset($calldata['item']['item_type']) ? $calldata['item']['item_type'] : null;

		if ($itemtype) {
			$itemtypehook='cart_orderpaid_'.$itemtype;
			call_hooks($itemtypehook,$calldata);
			$hookdata["content"] .= isset($calldata["content"]) ? $calldata["content"] : '';
			unset($calldata["content"]);
			if (isset($calldata["error"]) && $calldata["error"]!=null) {
				$hookdata["errorcontent"][]=isset($calldata["errorcontent"]) ? $calldata["errorcontent"] : null;
				$hookdata["error"][]=$calldata["error"];
			}
		}
	}

	unset($calldata);
	$order=cart_loadorder($orderhash);
	$calldata=Array('order'=>$order,"error"=>null,"content"=>null);
	call_hooks('cart_orderpaid',$calldata);
	$hookdata["content"].=isset($calldata["content"]) ? $calldata["content"] : '';
	unset($calldata["content"]);
	if (isset($calldata["error"]) && $calldata["error"]!=null) {
		$hookdata["errorcontent"][]=isset($calldata["errorcontent"]) ? $calldata["errorcontent"] : null;
		$hookdata["error"][]=$calldata["error"];
	}
	return;
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
	logger ('[cart] getconfig ('.$param.')');
	return get_config("cart",$param);
}

function cart_setsysconfig($param,$val) {
		logger ('[cart] setsysconfig ('.$param.') as ('.$val.').',LOGGER_DEBUG);
		return set_config("cart",$param,$val);
}

function cart_delsysconfig($param) {
		logger ('[cart] delsysconfig ('.$param.').',LOGGER_DEBUG);
		return del_config("cart",$param);
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

function cart_install(&$a) {
		logger ('[cart] Install start.',LOGGER_DEBUG);
	if (cart_dbUpgrade () == UPDATE_FAILED) {
		notice ('[cart] Install error - Abort installation.');
		logger ('[cart] Install error - Abort installation.');
		cart_setsysconfig("status","install error");
		return;
	}
	notice ('[cart] Installed successfully.');
	logger ('[cart] Installed successfully.');
	cart_setsysconfig("appver",$cart_version);
	cart_setsysconfig("status","ready");
	cart_setsysconfig("dropTablesOnUninstall",0);
}
	
function cart_uninstall() {
	$dropTablesOnUninstall = intval(cart_getsysconfig("dropTablesOnUninstall"));
  	logger ('[cart] Uninstall start.',LOGGER_DEBUG);
	if ($dropTablesOnUinstall === 1) {
  	        logger ('[cart] DB Cleanup table.',LOGGER_DEBUG);
		cart_dbCleanup ();
	        cart_delsysconfig("dbver");
	}
	
	cart_delsysconfig("appver");
	notice ('[cart] Uninstalled.');
	logger ('[cart] Uninstalled.',LOGGER_DEBUG);
	cart_setsysconfig("status","uninstalled");
	logger ('[cart] Set sysconfig as uninstalled.',LOGGER_DEBUG);
}

function cart_load(){
	Zotlabs\Extend\Hook::register('construct_page', 'addon/cart/cart.php', 'cart_construct_page');
	Zotlabs\Extend\Hook::register('feature_settings', 'addon/cart/cart.php', 'cart_settings');
	Zotlabs\Extend\Hook::register('feature_settings_post', 'addon/cart/cart.php', 'cart_settings_post');
	Zotlabs\Extend\Hook::register('cart_do_additem','addon/cart/cart.php','cart_do_additem');
	Zotlabs\Extend\Hook::register('cart_order_additem','addon/cart/cart.php','cart_additem_hook');
	Zotlabs\Extend\Hook::register('cart_do_updateitem','addon/cart/cart.php','cart_do_updateitem');
	Zotlabs\Extend\Hook::register('cart_order_updateitem','addon/cart/cart.php','cart_updateitem_hook');
	Zotlabs\Extend\Hook::register('cart_checkout','addon/cart/cart.php','cart_checkout_hook');
	Zotlabs\Extend\Hook::register('cart_do_checkout','addon/cart/cart.php','cart_do_checkout');
	Zotlabs\Extend\Hook::register('cart_orderpaid','addon/cart/cart.php','cart_orderpaid_hook');
	Zotlabs\Extend\Hook::register('cart_do_orderpaid','addon/cart/cart.php','cart_do_orderpaid');
	Zotlabs\Extend\Hook::register('cart_before_checkout','addon/cart/cart.php','cart_calc_totals',1,10);
	Zotlabs\Extend\Hook::register('cart_calc_totals','addon/cart/cart.php','cart_calc_totals',1,50);
	Zotlabs\Extend\Hook::register('cart_display_after','addon/cart/cart.php','cart_display_totals',1,99);
	Zotlabs\Extend\Hook::register('cart_mod_content','addon/cart/cart.php','cart_mod_content',1,99);
	Zotlabs\Extend\Hook::register('cart_post_add_item','addon/cart/cart.php','cart_post_add_item');
}

function cart_unload(){
	Zotlabs\Extend\Hook::unregister('construct_page', 'addon/cart/cart.php', 'cart_construct_page');
	Zotlabs\Extend\Hook::unregister('feature_settings', 'addon/cart/cart.php', 'cart_settings');
	Zotlabs\Extend\Hook::unregister('feature_settings_post', 'addon/cart/cart.php', 'cart_settings_post');
	Zotlabs\Extend\Hook::unregister('cart_do_additem','addon/cart/cart.php','cart_do_additem');
	Zotlabs\Extend\Hook::unregister('cart_order_additem','addon/cart/cart.php','cart_additem_hook');
	Zotlabs\Extend\Hook::unregister('cart_do_updateitem','addon/cart/cart.php','cart_do_updateitem');
	Zotlabs\Extend\Hook::unregister('cart_order_updateitem','addon/cart/cart.php','cart_updateitem_hook');
	Zotlabs\Extend\Hook::unregister('cart_checkout','addon/cart/cart.php','cart_checkout_hook');
	Zotlabs\Extend\Hook::unregister('cart_do_checkout','addon/cart/cart.php','cart_do_checkout');
	Zotlabs\Extend\Hook::unregister('cart_orderpaid','addon/cart/cart.php','cart_orderpaid_hook');
	Zotlabs\Extend\Hook::unregister('cart_do_orderpaid','addon/cart/cart.php','cart_do_orderpaid');
	Zotlabs\Extend\Hook::unregister('cart_before_checkout','addon/cart/cart.php','cart_calc_totals',1,10);
	Zotlabs\Extend\Hook::unregister('cart_calc_totals','addon/cart/cart.php','cart_calc_totals',1,10);
	Zotlabs\Extend\Hook::unregister('cart_display_after','addon/cart/cart.php','cart_display_totals',1,99);
	Zotlabs\Extend\Hook::unregister('cart_mod_content','addon/cart/cart.php','cart_mod_content',1,99);
	Zotlabs\Extend\Hook::unregister('cart_post_add_item','addon/cart/cart.php','cart_post_add_item');
}

function cart_module() { return; }

/*
 *
 *  CALLABLE HOOKS:
 **		cart_do_additem   @param Array("iteminfo"=>{itemarray},"content"=>$c)
 **		cart_do_updateitem @param Array("iteminfo"=>{itemarray},"content"=>$c)
 *		cart_do_checkout @param $content
 *      cart_processor_register  @param:  Array ("uniqueslug"=>"payprocessor_hookname")
 * 		cart_itemtype_register   @param:  Array ("uniqueslug"=>Array{meta parameters})
 * 
 *  CALLED HOOKS:
 *		cart_post_{formhandle} (&$c)  ($c=returned content)
 ***	cart_display_before (Array("orderhash"=>$orderhash,"content"=>$c))
 ***	cart_display_after (Array("orderhash"=>$orderhash,"content"=>$c))
 ***    cart_display_item (Array("item"=>$iteminfo_array,"content"=>$c))
 ***	cart_display_before_{itemtype} (Array("item"=>$iteminfo_array,"content"=>$c))
 ***	cart_display_after_{itemtype} (Array("item"=>$iteminfo_array,"content"=>$c))
 **		cart_do_display (Array("orderhash"=>$orderhash,"content"=>$c))
 ***	cart_order_before_additem (Array("order"=>{order array},"item"=>{newitem array},"error"=>null))
 ***	cart_order_before_additem_{itemtype} (Array("order"=>{order array},"item"=>{newitem array},"error"=>null))
 *	            each hook handler should simply return if ["error"] != null
 ***	cart_order_additem (Array("order"=>{order array},"item"=>{newitem array}))
 ***	cart_order_additem_{itemtype} (Array("order"=>{order array},"item"=>{newitem array}))
 ***	cart_order_after_additem (Array("order"=>{order array},"item"=>{newitem array}))
 ***	cart_order_after_additem_{itemtype} (Array("order"=>{order array},"item"=>{newitem array}))
 ***	cart_order_before_updateitem (Array("order"=>{order array},"item"=>{updated item array},"error"=>null))
 ***	cart_order_before_updateitem_{itemtype} (Array("order"=>{order array},"item"=>{updated item array},"error"=>null))
 *			     each hook handler should simply return if ["error"] != null
 ***	cart_order_updateitem (Array("order"=>{order array},"item"=>{updated item array}))
 ***	cart_order_updateitem_{itemtype} (Array("order"=>{order array},"item"=>{updated item array}))
 ***	cart_order_after_updateitem (Array("order"=>{order array},"item"=>{updated item array}))
 ***	cart_order_after_updateitem_{itemtype} (Array("order"=>{order array},"item"=>{updated item array}))
 * 
 ***    cart_before_checkout (Array("order"=>{order array},"error"=>null,"formcontent"=>null))
 ***	cart_before_checkout_{itemtype} (Array("item"=>{item array},"error"=>null,"formcontent"=>null))
 *              each hook handler should simply return if ["error"] != null
 * 				forms can be returned in ["formcontent"]
 * 				each returned form should have a hidden value "cart_formhandle"
 * 				which is handled by a hook cart_post_{formhandle}
 ***    cart_checkout ({order array})
 ***    cart_after_checkout ({order array})
 ***	cart_after_checkout_{itemtype} ({item array})
 ***	cart_orderpaid ({order array})
 ***	cart_orderpaid_{itemtype} ({item array})
 * 		cart_fulfill_item ({item array})
 * 		cart_fulfill_item_{itemtype} ({item array})
 ***    cart_get_catalog ({items array})
 ***    cart_filter_catalog ({items array})
 ***    cart_aside_filter ({aside_content})
 ***    cart_mainmenu_filter ({menu array})
 *                   ["order"]=sort order
 *                   ["heading"]=heading to display item under
 * 					 ["text"]=Menu item text
 *                   ["URL"]=URL to link to
 *		
 *
 */

function cart_settings_post(&$a,&$s) {
	if(! local_channel())
		return;

        $prev_enable = get_pconfig(local_channel(),'cart','enable');

	set_pconfig( local_channel(), 'cart', 'enable', $_POST['enable_cart'] );
        if (!isset($_POST['enable_cart']) || $_POST['enable_cart'] != $prev_enable) {
            return;
        }
	set_pconfig( local_channel(), 'cart', 'enable_test_catalog', $_POST['enable_test_catalog'] );
	set_pconfig( local_channel(), 'cart', 'enable_manual_payments', $_POST['enable_manual_payments'] );

}
function cart_plugin_admin_post(&$a,&$s) {
/*
	if(! local_channel())
		return;

	set_pconfig( local_channel(), 'cart', 'enable_test_catalog', $_POST['enable_test_catalog'] );
	set_pconfig( local_channel(), 'cart', 'enable_manual_payments', $_POST['enable_manual_payments'] );
*/

}

function cart_settings(&$s) {
	$id = local_channel();
	if (! $id)
		return;

	$enablecart = get_pconfig ($id,'cart','enable');
	$sc = replace_macros(get_markup_template('field_checkbox.tpl'), array(
				     '$field'	=> array('enable_cart', t('Enable Shopping Cart'), 
							 (isset($enablecart) ? $enablecart : 0), 
							 '',array(t('No'),t('Yes')))));

        if (isset($enablecart)  && $enablecart == 1) {
	    $testcatalog = get_pconfig ($id,'cart','enable_test_catalog');
	    $sc .= replace_macros(get_markup_template('field_checkbox.tpl'), array(
				     '$field'	=> array('enable_test_catalog', t('Enable Test Catalog'), 
							 (isset($testcatalog) ? $testcatalog : 0), 
							 '',array(t('No'),t('Yes')))));


	    $manualpayments = get_pconfig ($id,'cart','enable_manual_payments');

	    $sc .= replace_macros(get_markup_template('field_checkbox.tpl'), array(
				     '$field'	=> array('enable_manual_payments', t('Enable Manual Payments'), 
							 (isset($manualpayments) ? $manualpayments : 0), 
							 '',array(t('No'),t('Yes')))));

        }

	$s .= replace_macros(get_markup_template('generic_addon_settings.tpl'), array(
				     '$addon' 	=> array('cart',
							 t('Base Cart Settings'), '', 
							 t('Submit')),
				     '$content'	=> $sc));
        //return $s;

}
function cart_plugin_admin(&$a,&$s) {
/*

	$s .= replace_macros(get_markup_template('generic_addon_settings.tpl'), array(
				     '$addon' 	=> array('cart',
							 t('Cart Settings'), '', 
							 t('Submit')),
				     '$content'	=> $sc));
*/

}

function cart_init() {
    // Determine which channel's cart to display to the observer
    $nick = null;
    if (argc() > 1)
        $nick = argv(1); // if the channel name is in the URL, use that

    if (! $nick && local_channel()) { // if no channel name was provided, assume the current logged in channel
        $channel = \App::get_channel();
        if ($channel && $channel['channel_address']) {
            $nick = $channel['channel_address'];
            goaway(z_root() . '/cart/' . $nick);
        }
    }
    if (! $nick) {
        notice( t('Profile Unavailable.') . EOL);
        goaway(z_root());
    }

    profile_load($nick);

}

function cart_post_add_item () {
	notice (t('Add Item') . EOL);
	$items=Array();
        // HERE!!!
	Zotlabs\Extend\Hook::insert('cart_get_catalog','cart_get_test_catalog',1,0);
	call_hooks('cart_get_catalog',$items);

	$newitem = $items[$_POST["add"]];
        $newitem["item_qty"]=1;
	$hookdata=Array("content"=>'',"iteminfo"=>$newitem);
	call_hooks('cart_do_additem',$hookdata);
}
	
function cart_post(&$a) {
	$cart_formname=preg_replace('/[^a-zA-Z0-9\_]/','',$_POST["cart_posthook"]);
	$formhook = "cart_post_".$cart_formname;
	notice (t('Add Item: ') . $formhook . EOL);
	call_hooks($formhook);
	$base_url = ( isset($_SERVER['HTTPS']) && $_SERVER['HTTPS']=='on' ? 'https' : 'http' ) . '://' .  $_SERVER['HTTP_HOST'];
	$url = $base_url . $_SERVER["REQUEST_URI"];
	goaway($url);
}

function cart_mod_content(&$arr) {
  $aside = "";
  call_hooks ('cart_aside_filter',$aside);
  \App::$page['aside'] =  $aside;
  $arr['content'] = cart_pagecontent($a);
  $arr['replace'] = true;
  return ;
}

function cart_pagecontent($a=null) {

    if(observer_prohibited(true)) {
        return login();
    }

        $channelid = App::$profile['uid'];

    $enablecart = get_pconfig ($channelid,'cart','enable');
    if(!isset($enablecart) || $enablecart==0) {
        notice( t('Cart Not Enabled (profile: '.App::$profile['uid'].')') . EOL);
        return;
    }

    $sellernick = argv(1);

    $seller = channelx_by_nick($sellernick);

    if(! $seller) {
          notice( t('Invalid channel') . EOL);
          goaway('/' . argv(0));
    }

    $observer_hash = get_observer_hash();

    $is_seller = ((local_channel()) && (local_channel() == \App::$profile['profile_uid']) ? true : false);
	
    // Determine if the observer is the channel owner so the ACL dialog can be populated
    if ($is_seller) {
		// DO Seller Specific Setup
		nav_set_selected('Cart');
	}

	if ((argc() >= 3) && (argv(2) === 'order')) {
		$orderhash=argv(3);

		if ($orderhash == '') {
			$orderhash = cart_getorderhash(false);
		}

		if (!$orderhash) {
			notice ( t('Order not found.' . EOL));
			return "<h1>Order Not Found</h1>";
		}
		
		$cart_template = get_markup_template('basic_cart.tpl','addon/cart/');
		call_hooks('cart_show_order_filter',$cart_template);
		$order = cart_loadorder($orderhash);
                logger("[cart] ORDER: ".print_r($order,true));
		return replace_macros($cart_template, $order);
	}
		
		
    if ((argc() >= 3) && (argv(2) == 'catalog')) {
		$items = Array();

		$testcatalog = get_pconfig ( \App::$profile['profile_uid'] ,'cart','enable_test_catalog');
		$testcatalog = $testcatalog ? $testcatalog : 0;

		if ($testcatalog) {
			Zotlabs\Extend\Hook::insert('cart_get_catalog','cart_get_test_catalog',1,0);
		}
		call_hooks('cart_get_catalog',$items);
		call_hooks('cart_filter_catalog',$items);
		if (count($items)<1) {
			return "<H1>Catalog has no items</H1>";
		}
		$template = get_markup_template('basic_catalog.tpl','addon/cart/');
		return replace_macros($template, array('$items'	=> $items ));
	}

	$menu = Array();

	call_hooks('cart_mainmenu_filter',$menu);

    
}

function cart_get_test_catalog (&$items) {

	if (!is_array($items)) {$items = Array();}

	$items= array_merge($items,Array (
		"sku-1"=>Array("item_sku"=>"sku-1","item_desc"=>"Description Item 1","item_price"=>5.55),
		"sku-2"=>Array("item_sku"=>"sku-2","item_desc"=>"Description Item 2","item_price"=>6.55),
		"sku-3"=>Array("item_sku"=>"sku-3","item_desc"=>"Description Item 3","item_price"=>7.55),
		"sku-4"=>Array("item_sku"=>"sku-4","item_desc"=>"Description Item 4","item_price"=>8.55),
		"sku-5"=>Array("item_sku"=>"sku-5","item_desc"=>"Description Item 5","item_price"=>9.55),
		"sku-6"=>Array("item_sku"=>"sku-6","item_desc"=>"Description Item 6","item_price"=>10.55)
	));
	
}

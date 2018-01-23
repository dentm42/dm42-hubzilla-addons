{{include "./basic_cart.tpl"}}
<h1>Payment Options</h1>
<form method="post">
<input type="hidden" name="cart_posthook" value="checkout_choosepayment">
{{foreach from=$paymentopts key=payslug item=payopt}}
<input type="radio" name="paymenttypeslug" value="{{$payslug}}">{{$payopt.html}} <BR>
{{/foreach}}
<button class="btn btn-primary" type="submit" name="add" id="pay" value="pay">Continue with Payment</button>
</form>

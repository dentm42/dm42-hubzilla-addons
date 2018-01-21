{{if !$item.item_fulfilled}}
<form method="post">
<input type="hidden" name="cart_posthook" value="myshop_fulfill">
<input type="hidden" name="itemid" value="{{$item.id}}">
<button class="btn btn-primary" type="submit" name="add" id="newchannel-submit-button" value="{{$item.item_sku}}">Fulfill</button>
</form>
{{if $item.item_exception}}
<form method="post">
<input type="hidden" name="cart_posthook" value="myshop_fulfill">
<input type="hidden" name="itemid" value="{{$item.id}}">
<input type="hidden" name="exception" value="false">
<button class="btn btn-primary" type="submit" name="clear_exception" value="{{$item.id}}">Clear Exception</button>
</form>
{{/if}}
{{/if}}
{{if $item.meta.notes}}
<ul>
{{foreach $item.meta.notes as $note}}
<li>{{$note}}
{{/foreach}}
</ul>
<form method="post">
<input type="hidden" name="cart_posthook" value="myshop_itemnote_add">
<input type="hidden" name="itemid" value="{{$item.id}}">
<textarea name="notetext" rows=5 cols=40></textarea>
<input type="checkbox" name="exception" value="true">EXCEPTION<br>
<button class="btn btn-primary" type="submit" name="add" id="cart-myshop-itemnote-add-button" value="add">Add Note</button>
</form>
{{/if}}

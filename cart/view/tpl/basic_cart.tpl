<h1>CART CONTENTS</h1>

<div class="dm42cart catalog">
  <div class='section-title-wrapper'>
    <div class="title">{{if $title}}{{$title}}{{else}}Order{{/if}}</div>
  </div>
  <div class='section-content-wrapper'>
    <form method="post">
    <input type="hidden" name="cart_posthook" value="add_item">
    <table>
        <tr>
            <th>Description</th>
            <th>Price each {{if $currencysymbol}}({{$currencysymbol}}){{/if}}</th>
            <th>Extended</th>
        </tr>
    {{foreach $items as $item}}
        <tr>
            <td>{{$item.item_desc}}</td>
            <td>{{$item.item_price}}</td>
            <td>{{$item.extended}}</td>
        </tr>
    {{/foreach}}
    <tr>
        <td></td>
        <td>Subtotal</td>
        <td>{{$totals.Subtotal}}</td>
    </tr>
    <tr>
        <td></td>
        <td>Tax Total</td>
        <td>{{$totals.Tax}}</td>
    </tr>
    <tr>
        <td></td>
        <td>Order Total</td>
        <td>{{$totals.OrderTotal}}</td>
    </tr>
    {{if $totals.Payment}}
    <tr>
        <td></td>
        <td>Payment</td>
        <td>{{$totals.Payment}}</td>
    </tr>
    {{/if}}
    </table>
    </form>
  </div>
</div>





    <table>
        <tr>
            <th></th>
            <th>Description</th>
            <th>Price each {{if $currencysymbol}}({{$currencysymbol}}){{/if}}</th>
        </tr>
    {{foreach $items as $item}}
    {{$itemtotal = $items.item_qty * $items.item_price}}
    {{$itemtax = $itemtotal * $items.item_tax_rate}}
        <tr>
            <td><button class="btn btn-primary" type="submit" name="add" id="newchannel-submit-button" value="{{$item.item_sku}}">Add</button></td>
            <td>{{$item.item_desc}}</td>
            <td>{{$item.item_price}}</td>
        </tr>
    {{/foreach}}
    </table>


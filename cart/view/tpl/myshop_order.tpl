<h1>CART CONTENTS</h1>

<div class="dm42cart catalog" style="width:100%;">
  <div class='section-title-wrapper'>
    <div class="title">{{if $title}}{{$title}}{{else}}Order{{/if}}</div>
  </div>
  <div class='section-content-wrapper' style="width:100%;">
    <table style="width:100%;">
        <tr>
            <th width=60%>Description</th>
            <th width=20% style="text-align:right;">Price each {{if $currencysymbol}}({{$currencysymbol}}){{/if}}</th>
            <th width=20% style="text-align:right;">Extended</th>
        </tr>
    {{foreach $items as $item}}
        <tr {{if $item.item_exeption}} class="cart-item-exception"{{/if}}>
            <td {{if $item.item_exeption}} class="cart-item-exception"{{/if}}>{{$item.item_desc}}
            {{if $item.xtrahtml}}
            {{/if}}
            </td>
            <td style="text-align:right;" {{if $item.item_exeption}} class="cart-item-exception"{{/if}}>{{$item.item_price}}</td>
            <td style="text-align:right;" {{if $item.item_exeption}} class="cart-item-exception"{{/if}}>{{$item.extended}}</td>
        </tr>
    {{/foreach}}
    <tr>
        <td></td>
        <th style="text-align:right;">Subtotal</th>
        <td style="text-align:right;">{{$totals.Subtotal}}</td>
    </tr>
    <tr>
        <td></td>
        <th style="text-align:right;">Tax Total</th>
        <td style="text-align:right;">{{$totals.Tax}}</td>
    </tr>
    <tr>
        <td></td>
        <th style="text-align:right;">Order Total</th>
        <td style="text-align:right;">{{$totals.OrderTotal}}</td>
    </tr>
    {{if $totals.Payment}}
    <tr>
        <td></td>
        <th>Payment</th>
        <td style="text-align:right;">{{$totals.Payment}}</td>
    </tr>
    {{/if}}
    </table>
  </div>
</div>

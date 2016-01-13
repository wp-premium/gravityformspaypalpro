<link rel="stylesheet" href="<?php echo GFPayPalPro::get_base_url() . "/css/confirmation.css"?>" />

<?php
if(empty($products["products"])){
    ?>
    <div class="error"><?php printf(__("Your cart is empty. Order could not be processed. %sReturn to site%s", "gravityformspaypalpro"), "<a href='" . home_url() . "'>", "</a>") ?></div>
    <?php
}
else{
    ?>
    <!-- begin product checkout table markup -->
    <form method='post'>
        <div class="gf_checkout_review_table_wrapper">

            <h2 class="gf_checkout_heading"><?php _e("Checkout", "gravityformspaypalpro") ?></h2>

            <table cellspacing="0" class="gf_checkout_review_table">
                <thead>
                    <tr>
                        <td colspan="4" class="gf_checkout_review_table_title"><?php _e("Order Review", "gravityformspaypalpro") ?></td>
                    </tr>
                    <tr class="gf_checkout_labels">
                        <th class="gf_checkout_review_item_quantity_label"><?php _e("Qty", "gravityformspaypalpro") ?></th>
                        <th class="gf_checkout_review_item_name_label"><?php _e("Product Name", "gravityformspaypalpro") ?></th>
                        <th class="gf_checkout_review_item_price_label"><?php _e("Price", "gravityformspaypalpro") ?></th>
                        <th class="gf_checkout_review_item_subtotal_label"><?php _e("Subtotal", "gravityformspaypalpro") ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php
                $evenodd = "oddrow";
                foreach($recurring_products as $product){
                    $price = GFCommon::to_number($product["price"]);
                    $evenodd = $evenodd == "oddrow" ? "evenrow" : "oddrow";
                    ?>
                    <tr class="gf_checkout_review_<?php echo $evenodd ?>">
                        <td class="gf_checkout_review_item_quantity"><?php echo $product["quantity"] ?></td>
                        <td class="gf_checkout_review_item_name"><?php echo esc_html($product["name"])?>
                            <?php
                            $options = array();
                            if(is_array(rgar($product, "options"))){
                                foreach($product["options"] as $option){
                                    $price += GFCommon::to_number($option["price"]);
                                    $options[] = $option["option_label"];
                                }
                            }
                            $subtotal = $price * $product["quantity"];
                            ?>
                            <span class="gf_checkout_review_item_description">
                                <?php echo esc_html(implode(", ", $options)) ?>
                            </span>
                        </td>
                        <td class="gf_checkout_review_item_price"><?php echo GFCommon::to_money($price, $entry["currency"]) ?></td>
                        <td class="gf_checkout_review_item_subtotal"><?php echo GFCommon::to_money($subtotal, $entry["currency"]) ?></td>
                    </tr>
                <?php
                }
                ?>
                </tbody>

                <tfoot>
                    <?php
                    if($recurring_amount){
                    ?>
                        <tr class="gf_checkout_review_subtotalrow">
                            <td colspan="3" class="gf_checkout_review_subtotal_label"><?php echo $recurring_label ?></td>
                            <td class="gf_checkout_review_item_subtotal"><?php echo GFCommon::to_money($recurring_amount, $entry["currency"])?></td>
                        </tr>
                    <?php
                    }

                    if($setup_fee || $trial_data){
                    ?>
                        <tr class="gf_checkout_labels">
                            <th colspan="3" class="gf_checkout_review_item_name_label">Other</th>
                            <th class="gf_checkout_review_item_price_label">Price</th>
                        </tr>
                    <?php
                    }

                    if($setup_fee){
                    ?>
                        <tr class="gf_checkout_review_extras">
                            <td colspan="3" class="gf_checkout_review_extra_name"><?php _e("One Time Setup & Processing Fee", "gravityformspaypalpro") ?>
                                <!--<span class="gf_checkout_review_item_description">Lorem ipsum dolor sit amet, consectetuer adipiscing elit. Aenean commodo ligula eget dolor. Aenean massa. Cum sociis natoque penatibus et magnis dis parturient montes, nascetur ridiculus mus. Donec quam felis, ultricies nec, pellentesque eu, pretium quis, sem.</span>-->
                            </td>
                            <td class="gf_checkout_review_extra_price"><?php echo GFCommon::to_money($setup_fee, $entry["currency"])?></td>
                        </tr>
                    <?php
                    }

                    if($trial_data){
                    ?>
                        <tr class="gf_checkout_review_extras">
                            <td colspan="3" class="gf_checkout_review_extra_name"><?php echo $trial_label ?>
                                <span class="gf_checkout_review_item_description"><?php _e("Your first recurring payment will be processed at the end of the trial period.", "gravityformspaypalpro") ?></span>
                            </td>
                            <td class="gf_checkout_review_extra_price"><?php echo GFCommon::to_money($trial_data["amount"], $entry["currency"])?></td>
                        </tr>
                    <?php
                    }
                    ?>

                    <tr class="gf_checkout_review_totalrow">
                        <td colspan="3" class="gf_checkout_review_total_label"><?php echo $total_label ?></td>
                        <td class="gf_checkout_review_item_total"><?php echo GFCommon::to_money($total_amount, $entry["currency"])?></td>
                    </tr>
                </tfoot>

            </table>
            <div class="gf_checkout_review_button_wrapper">
                <button class="gf_checkout_review_button" name='paypalpro_confim_payment' type='submit'><?php _e("Pay Now", "gravityformspaypal") ?><span></span></button>
            </div>

        </div>
    </form>
    <!-- end table markup -->
    <?php
}
?>
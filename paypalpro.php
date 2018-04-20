<?php
/*
Plugin Name: Gravity Forms PayPal Pro Add-On
Plugin URI: https://www.gravityforms.com
Description: Integrates Gravity Forms with PayPal Pro, enabling end users to purchase goods and services through Gravity Forms.
Version: 1.8.1
Author: rocketgenius
Author URI: https://www.rocketgenius.com
License: GPL-2.0+
Text Domain: gravityformspaypalpro
Domain Path: /languages

------------------------------------------------------------------------
Copyright 2009 - 2015 rocketgenius

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA 02111-1307 USA
*/

add_action('parse_request', array("GFPayPalPro", "process_ipn"));

add_action('init',  array('GFPayPalPro', 'init'));
register_activation_hook( __FILE__, array("GFPayPalPro", "add_permissions"));

class GFPayPalPro {

    private static $path = "gravityformspaypalpro/paypalpro.php";
    private static $url = "http://www.gravityforms.com";
    private static $slug = "gravityformspaypalpro";
    private static $version = "1.8.1";
    private static $min_gravityforms_version = "1.9.14";
    private static $production_url = "https://api-3t.paypal.com/nvp";
    private static $sandbox_url = "https://api-3t.sandbox.paypal.com/nvp";
    public static $production_express_checkout_url = "https://www.paypal.com/cgi-bin/webscr";
    public static $sandbox_express_checkout_url = "https://www.sandbox.paypal.com/cgi-bin/webscr";

    public static $transaction_response = "";
    private static $supported_fields = array("checkbox", "radio", "select", "text", "website", "textarea", "email", "hidden", "number", "phone", "multiselect", "post_title",
                                             "post_tags", "post_custom_field", "post_content", "post_excerpt");

    //Plugin starting point. Will load appropriate files
    public static function init(){

    	//supports logging
		add_filter("gform_logging_supported", array("GFPayPalPro", "set_logging_supported"));

        if( defined( 'RG_CURRENT_PAGE' ) ){

            if( RG_CURRENT_PAGE == "plugins.php" ){
                //loading translations
                load_plugin_textdomain('gravityformspaypalpro', FALSE, '/gravityformspaypalpro/languages' );

                add_action('after_plugin_row_' . self::$path, array('GFPayPalPro', 'plugin_row') );

                //force new remote request for version info on the plugin page
                self::flush_version_info();
            }

        }

        if(!self::is_gravityforms_supported())
           return;

        if(is_admin()){
            //loading translations
            load_plugin_textdomain('gravityformspaypalpro', FALSE, '/gravityformspaypalpro/languages' );

            //automatic upgrade hooks
            add_filter("transient_update_plugins", array('GFPayPalPro', 'check_update'));
            add_filter("site_transient_update_plugins", array('GFPayPalPro', 'check_update'));
            add_action('install_plugins_pre_plugin-information', array('GFPayPalPro', 'display_changelog'));

            //integrating with Members plugin
            if(function_exists('members_get_capabilities'))
                add_filter('members_get_capabilities', array("GFPayPalPro", "members_get_capabilities"));

            //creates the subnav left menu
            add_filter("gform_addon_navigation", array('GFPayPalPro', 'create_menu'));

            //enables credit card field
            add_filter("gform_enable_credit_card_field", "__return_true");

            //runs the setup when version changes
            self::setup();

            if(self::is_paypalpro_page()){

                //enqueueing sack for AJAX requests
                wp_enqueue_script(array("sack"));

                //loading data lib
                require_once(self::get_base_path() . "/data.php");

                //loading upgrade lib
                if(!class_exists("RGPayPalProUpgrade"))
                    require_once("plugin-upgrade.php");

                //loading Gravity Forms tooltips
                require_once(GFCommon::get_base_path() . "/tooltips.php");
                add_filter('gform_tooltips', array('GFPayPalPro', 'tooltips'));

            }
            else if(in_array(RG_CURRENT_PAGE, array("admin-ajax.php"))){

                //loading data class
                require_once(self::get_base_path() . "/data.php");

                add_action('wp_ajax_gf_paypalpro_update_feed_active', array('GFPayPalPro', 'update_feed_active'));
                add_action('wp_ajax_gf_select_paypalpro_form', array('GFPayPalPro', 'select_paypalpro_form'));
                add_action('wp_ajax_gf_cancel_paypalpro_subscription', array('GFPayPalPro', 'cancel_paypalpro_subscription'));

            }
            else if(RGForms::get("page") == "gf_settings"){
                RGForms::add_settings_page("PayPal Pro", array("GFPayPalPro", "settings_page"), self::get_base_url() . "/images/paypal_wordpress_icon_32.png");
            }
            else if(RGForms::get("page") == "gf_entries"){
                add_action('gform_entry_info',array("GFPayPalPro", "paypalpro_entry_info"), 10, 2);
	            add_filter( 'gform_enable_entry_info_payment_details', array( 'GFPayPalPro', 'disable_entry_info_payment' ), 10, 2 );

            }
        }
        else{
            //loading data class
            require_once(self::get_base_path() . "/data.php");

            if(self::get_payment_method() == "creditcard"){

                //handling credit card checkout.
                add_filter('gform_validation',array("GFPayPalPro", "paypalpro_validation"), 1000, 4);

            }
            else if(self::get_payment_method() == "paypalpro"){
                //handling post submission for PayPal Pro Express Checkout.
                add_filter("gform_confirmation", array("GFPayPalPro", "start_express_checkout"), 1000, 4);
            }

            //add_filter("gform_payment_methods", array("GFPayPalPro", "add_payment_method"), 10, 3);
            add_action('gform_after_submission',array("GFPayPalPro", "paypalpro_after_submission"), 10, 2);
            add_filter("gform_get_form_filter", array("GFPayPalPro", "maybe_confirm_express_checkout"));

            // ManageWP premium update filters
            add_filter( 'mwp_premium_update_notification', array('GFUser', 'premium_update_push') );
            add_filter( 'mwp_premium_perform_update', array('GFUser', 'premium_update') );
        }
    }

    public static function get_payment_method(){
        return "creditcard";
        //return rgpost("gform_payment_method");
    }

    public static function update_feed_active(){
        check_ajax_referer('gf_paypalpro_update_feed_active','gf_paypalpro_update_feed_active');
        $id = $_POST["feed_id"];
        $feed = GFPayPalProData::get_feed($id);
        GFPayPalProData::update_feed($id, $feed["form_id"], $_POST["is_active"], $feed["meta"]);
    }

    //-------------- Automatic upgrade ---------------------------------------

            //Integration with ManageWP
    public static function premium_update_push( $premium_update ){

        if( !function_exists( 'get_plugin_data' ) )
            include_once( ABSPATH.'wp-admin/includes/plugin.php');

		//loading upgrade lib
		if( ! class_exists( 'RGPayPalProUpgrade' ) ){
			require_once( 'plugin-upgrade.php' );
		}
		$update = RGPayPalProUpgrade::get_version_info( self::$slug, self::get_key(), self::$version );

        if( $update["is_valid_key"] == true && version_compare(self::$version, $update["version"], '<') ){
            $plugin_data = get_plugin_data( __FILE__ );
            $plugin_data['type'] = 'plugin';
            $plugin_data['slug'] = self::$path;
            $plugin_data['new_version'] = isset($update['version']) ? $update['version'] : false ;
            $premium_update[] = $plugin_data;
        }

        return $premium_update;
    }

    //Integration with ManageWP
    public static function premium_update( $premium_update ){

        if( !function_exists( 'get_plugin_data' ) )
            include_once( ABSPATH.'wp-admin/includes/plugin.php');

		//loading upgrade lib
		if( ! class_exists( 'RGPayPalProUpgrade' ) ){
			require_once( 'plugin-upgrade.php' );
		}
		$update = RGPayPalProUpgrade::get_version_info( self::$slug, self::get_key(), self::$version );

        if( $update["is_valid_key"] == true && version_compare(self::$version, $update["version"], '<') ){
            $plugin_data = get_plugin_data( __FILE__ );
            $plugin_data['slug'] = self::$path;
            $plugin_data['type'] = 'plugin';
            $plugin_data['url'] = isset($update["url"]) ? $update["url"] : false; // OR provide your own callback function for managing the update

            array_push($premium_update, $plugin_data);
        }
        return $premium_update;
    }

    public static function flush_version_info(){
        if(!class_exists("RGPayPalProUpgrade"))
            require_once("plugin-upgrade.php");

        RGPayPalProUpgrade::set_version_info(false);
    }

    public static function plugin_row(){
        if(!self::is_gravityforms_supported()){
            $message = sprintf(__("Gravity Forms " . self::$min_gravityforms_version . " is required. Activate it now or %spurchase it today!%s", "gravityformspaypalpro"), "<a href='http://www.gravityforms.com'>", "</a>");
            RGPayPalProUpgrade::display_plugin_message($message, true);
        }
        else{
            $version_info = RGPayPalProUpgrade::get_version_info(self::$slug, self::get_key(), self::$version);

            if(!$version_info["is_valid_key"]){
                $new_version = version_compare(self::$version, $version_info["version"], '<') ? __('There is a new version of Gravity Forms PayPal Pro Add-On available.', 'gravityformspaypalpro') .' <a class="thickbox" title="Gravity Forms PayPal Pro Add-On" href="plugin-install.php?tab=plugin-information&plugin=' . self::$slug . '&TB_iframe=true&width=640&height=808">'. sprintf(__('View version %s Details', 'gravityformspaypalpro'), $version_info["version"]) . '</a>. ' : '';
                $message = $new_version . sprintf(__('%sRegister%s your copy of Gravity Forms to receive access to automatic upgrades and support. Need a license key? %sPurchase one now%s.', 'gravityformspaypalpro'), '<a href="admin.php?page=gf_settings">', '</a>', '<a href="http://www.gravityforms.com">', '</a>') . '</div></td>';
                RGPayPalProUpgrade::display_plugin_message($message);
            }
        }
    }

    //Displays current version details on Plugin's page
    public static function display_changelog(){
        if($_REQUEST["plugin"] != self::$slug)
            return;

        //loading upgrade lib
        if(!class_exists("RGPayPalProUpgrade"))
            require_once("plugin-upgrade.php");

        RGPayPalProUpgrade::display_changelog(self::$slug, self::get_key(), self::$version);
    }

    public static function check_update($update_plugins_option){
        if(!class_exists("RGPayPalProUpgrade"))
            require_once("plugin-upgrade.php");

        return RGPayPalProUpgrade::check_update(self::$path, self::$slug, self::$url, self::$slug, self::get_key(), self::$version, $update_plugins_option);
    }

    private static function get_key(){
        if(self::is_gravityforms_supported())
            return GFCommon::get_key();
        else
            return "";
    }
    //------------------------------------------------------------------------

    public static function add_payment_method($payment_methods, $field, $form_id){
        $form = RGFormsModel::get_form_meta($form_id);

        //Getting settings associated with this form
        require_once(self::get_base_path() . "/data.php");
        $configs = GFPayPalProData::get_feed_by_form($form["id"]);

        if($configs)
            $payment_methods[] = array("key" => "paypalpro", "label"=>"PayPal");

        return $payment_methods;
    }

    //Creates PayPal Pro left nav menu under Forms
    public static function create_menu($menus){

        // Adding submenu if user has access
        $permission = self::has_access("gravityforms_paypalpro");
        if(!empty($permission))
            $menus[] = array("name" => "gf_paypalpro", "label" => __("PayPal Pro", "gravityformspaypalpro"), "callback" =>  array("GFPayPalPro", "paypalpro_page"), "permission" => $permission);

        return $menus;
    }

    //Creates or updates database tables. Will only run when version changes
    private static function setup(){
        if(get_option("gf_paypalpro_version") != self::$version){
            //loading data lib
            require_once(self::get_base_path() . "/data.php");
            GFPayPalProData::update_table();
        }

        update_option("gf_paypalpro_version", self::$version);
    }

    //Adds feed tooltips to the list of tooltips
    public static function tooltips($tooltips){
        $paypalpro_tooltips = array(
            "paypalpro_transaction_type" => "<h6>" . __("Transaction Type", "gravityformspaypalpro") . "</h6>" . __("Select which PayPal Pro transaction type should be used. Products and Services or Subscription.", "gravityformspaypalpro"),
            "paypalpro_gravity_form" => "<h6>" . __("Gravity Form", "gravityformspaypalpro") . "</h6>" . __("Select which Gravity Forms you would like to integrate with PayPal Pro.", "gravityformspaypalpro"),
            "paypalpro_customer" => "<h6>" . __("Customer", "gravityformspaypalpro") . "</h6>" . __("Map your Form Fields to the available PayPal Pro customer information fields.", "gravityformspaypalpro"),
            "paypalpro_page_style" => "<h6>" . __("Page Style", "gravityformspaypalpro") . "</h6>" . __("This option allows you to select which PayPal Pro page style should be used if you have set up a custom payment page style with PayPal Pro.", "gravityformspaypalpro"),
            "paypalpro_continue_button_label" => "<h6>" . __("Continue Button Label", "gravityformspaypalpro") . "</h6>" . __("Enter the text that should appear on the continue button once payment has been completed via PayPal Pro.", "gravityformspaypalpro"),
            "paypalpro_cancel_url" => "<h6>" . __("Cancel URL", "gravityformspaypalpro") . "</h6>" . __("Enter the URL the user should be sent to should they cancel before completing their PayPal Pro payment.", "gravityformspaypalpro"),
            "paypalpro_options" => "<h6>" . __("Options", "gravityformspaypalpro") . "</h6>" . __("Turn on or off the available PayPal Pro checkout options.", "gravityformspaypalpro"),
            "paypalpro_recurring_amount" => "<h6>" . __("Recurring Amount", "gravityformspaypalpro") . "</h6>" . __("Select which field determines the recurring payment amount, or select 'Form Total' to use the total of all pricing fields as the recurring amount.", "gravityformspaypalpro"),
            "paypalpro_billing_cycle" => "<h6>" . __("Billing Cycle", "gravityformspaypalpro") . "</h6>" . __("Select your billing cycle.  This determines how often the recurring payment should occur.", "gravityformspaypalpro"),
            "paypalpro_recurring_times" => "<h6>" . __("Recurring Times", "gravityformspaypalpro") . "</h6>" . __("Select how many times the recurring payment should be made.  The default is to bill the customer until the subscription is canceled.", "gravityformspaypalpro"),
            "paypalpro_trial_period_enable" => "<h6>" . __("Trial Period", "gravityformspaypalpro") . "</h6>" . __("Enable a trial period. When a trial period is enabled, recurring payments will only begin after the trial period has expired.", "gravityformspaypalpro"),
            "paypalpro_trial_amount" => "<h6>" . __("Trial Amount", "gravityformspaypalpro") . "</h6>" . __("Select which field determines the trial payment amount, or check 'Free' for a free trial.", "gravityformspaypalpro"),
            "paypalpro_trial_period" => "<h6>" . __("Trial Billing Cycle", "gravityformspaypalpro") . "</h6>" . __("Select your trial billing cycle.  This determines how often the trial payment should occur.", "gravityformspaypalpro"),
            "paypalpro_trial_recurring_times" => "<h6>" . __("Trial Recurring Times", "gravityformspaypalpro") . "</h6>" . __("Select the number of billing occurrences or payments in the trial period.", "gravityformspaypalpro"),
            "paypalpro_setup_fee_enable" => "<h6>" . __("Setup Fee", "gravityformspaypalpro") . "</h6>" . __("Enable setup fee to charge a one time fee before the recurring payments begin.", "gravityformspaypalpro"),
            "paypalpro_conditional" => "<h6>" . __("PayPal Pro Condition", "gravityformspaypalpro") . "</h6>" . __("When the PayPal Pro condition is enabled, form submissions will only be sent to PayPal Pro when the condition is met. When disabled all form submissions will be sent to PayPal Pro.", "gravityformspaypalpro")
        );
        return array_merge($tooltips, $paypalpro_tooltips);
    }

    public static function paypalpro_page(){
        $view = rgget("view");
        if($view == "edit")
            self::edit_page(rgget("id"));
        else if($view == "stats")
            self::stats_page(rgget("id"));
        else
            self::list_page();
    }

    //Displays the paypalpro feeds list page
    private static function list_page(){
        if(!self::is_gravityforms_supported()){
            die(__(sprintf("PayPal Pro Add-On requires Gravity Forms %s. Upgrade automatically on the %sPlugin page%s.", self::$min_gravityforms_version, "<a href='plugins.php'>", "</a>"), "gravityformspaypalpro"));
        }

        if(rgpost('action') == "delete"){
            check_admin_referer("list_action", "gf_paypalpro_list");

            $id = absint($_POST["action_argument"]);
            GFPayPalProData::delete_feed($id);
            ?>
            <div class="updated fade" style="padding:6px"><?php _e("Feed deleted.", "gravityformspaypalpro") ?></div>
            <?php
        }
        else if (!empty($_POST["bulk_action"])){
            check_admin_referer("list_action", "gf_paypalpro_list");
            $selected_feeds = $_POST["feed"];
            if(is_array($selected_feeds)){
                foreach($selected_feeds as $feed_id)
                    GFPayPalProData::delete_feed($feed_id);
            }
            ?>
            <div class="updated fade" style="padding:6px"><?php _e("Feeds deleted.", "gravityformspaypalpro") ?></div>
            <?php
        }

        $settings = get_option("gf_paypalpro_settings");
        $is_settings_configured = is_array($settings) && !rgempty("username", $settings) && !rgempty("password", $settings) && !rgempty("signature", $settings);

        ?>
        <div class="wrap">
            <img alt="<?php _e("PayPal Pro Transactions", "gravityformspaypalpro") ?>" src="<?php echo self::get_base_url()?>/images/paypal_wordpress_icon_32.png" style="float:left; margin:15px 7px 0 0;"/>
            <h2><?php
            _e("PayPal Pro Forms", "gravityformspaypalpro");

            if($is_settings_configured){
                ?>
                <a class="button add-new-h2" href="admin.php?page=gf_paypalpro&view=edit&id=0"><?php _e("Add New", "gravityformspaypalpro") ?></a>
                <?php
            }
            ?>
            </h2>

            <form id="feed_form" method="post">
                <?php wp_nonce_field('list_action', 'gf_paypalpro_list') ?>
                <input type="hidden" id="action" name="action"/>
                <input type="hidden" id="action_argument" name="action_argument"/>

                <div class="tablenav">
                    <div class="alignleft actions" style="padding:8px 0 7px 0;">
                        <label class="hidden" for="bulk_action"><?php _e("Bulk action", "gravityformspaypalpro") ?></label>
                        <select name="bulk_action" id="bulk_action">
                            <option value=''> <?php _e("Bulk action", "gravityformspaypalpro") ?> </option>
                            <option value='delete'><?php _e("Delete", "gravityformspaypalpro") ?></option>
                        </select>
                        <?php
                        echo '<input type="submit" class="button" value="' . __("Apply", "gravityformspaypalpro") . '" onclick="if( jQuery(\'#bulk_action\').val() == \'delete\' && !confirm(\'' . __("Delete selected feeds? ", "gravityformspaypalpro") . __("\'Cancel\' to stop, \'OK\' to delete.", "gravityformspaypalpro") .'\')) { return false; } return true;"/>';
                        ?>
                    </div>
                </div>
                <table class="widefat fixed" cellspacing="0">
                    <thead>
                        <tr>
                            <th scope="col" id="cb" class="manage-column column-cb check-column" style=""><input type="checkbox" /></th>
                            <th scope="col" id="active" class="manage-column check-column"></th>
                            <th scope="col" class="manage-column"><?php _e("Form", "gravityformspaypalpro") ?></th>
                            <th scope="col" class="manage-column"><?php _e("Transaction Type", "gravityformspaypalpro") ?></th>
                        </tr>
                    </thead>

                    <tfoot>
                        <tr>
                            <th scope="col" id="cb" class="manage-column column-cb check-column" style=""><input type="checkbox" /></th>
                            <th scope="col" id="active" class="manage-column check-column"></th>
                            <th scope="col" class="manage-column"><?php _e("Form", "gravityformspaypalpro") ?></th>
                            <th scope="col" class="manage-column"><?php _e("Transaction Type", "gravityformspaypalpro") ?></th>
                        </tr>
                    </tfoot>

                    <tbody class="list:user user-list">
                        <?php

                        $feeds = GFPayPalProData::get_feeds();

                        if(!$is_settings_configured){
                            ?>
                            <tr>
                                <td colspan="4" style="padding:20px;">
                                    <?php echo sprintf(__("To get started, please configure your %sPayPal Pro Settings%s.", "gravityformspaypalpro"), '<a href="admin.php?page=gf_settings&addon=PayPal Pro">', "</a>"); ?>
                                </td>
                            </tr>
                            <?php
                        }
                        else if(is_array($feeds) && sizeof($feeds) > 0){
                            foreach($feeds as $feed){
                                ?>
                                <tr class='author-self status-inherit' valign="top">
                                    <th scope="row" class="check-column"><input type="checkbox" name="feed[]" value="<?php echo $feed["id"] ?>"/></th>
                                    <td><img src="<?php echo self::get_base_url() ?>/images/active<?php echo intval($feed["is_active"]) ?>.png" alt="<?php echo $feed["is_active"] ? __("Active", "gravityformspaypalpro") : __("Inactive", "gravityformspaypalpro");?>" title="<?php echo $feed["is_active"] ? __("Active", "gravityformspaypalpro") : __("Inactive", "gravityformspaypalpro");?>" onclick="ToggleActive(this, <?php echo $feed['id'] ?>); " /></td>
                                    <td class="column-title">
                                        <a href="admin.php?page=gf_paypalpro&view=edit&id=<?php echo $feed["id"] ?>" title="<?php _e("Edit", "gravityformspaypalpro") ?>"><?php echo $feed["form_title"] ?></a>
                                        <div class="row-actions">
                                            <span class="edit">
                                            <a title="<?php _e("Edit", "gravityformspaypalpro")?>" href="admin.php?page=gf_paypalpro&view=edit&id=<?php echo $feed["id"] ?>" ><?php _e("Edit", "gravityformspaypalpro") ?></a>
                                            |
                                            </span>
                                            <span class="view">
                                            <a title="<?php _e("View Stats", "gravityformspaypalpro")?>" href="admin.php?page=gf_paypalpro&view=stats&id=<?php echo $feed["id"] ?>"><?php _e("Stats", "gravityformspaypalpro") ?></a>
                                            |
                                            </span>
                                            <span class="view">
                                            <a title="<?php _e("View Entries", "gravityformspaypalpro")?>" href="admin.php?page=gf_entries&view=entries&id=<?php echo $feed["form_id"] ?>"><?php _e("Entries", "gravityformspaypalpro") ?></a>
                                            |
                                            </span>
                                            <span class="trash">
                                            <a title="<?php _e("Delete", "gravityformspaypalpro") ?>" href="javascript: if(confirm('<?php _e("Delete this feed? ", "gravityformspaypalpro") ?> <?php _e("\'Cancel\' to stop, \'OK\' to delete.", "gravityformspaypalpro") ?>')){ DeleteSetting(<?php echo $feed["id"] ?>);}"><?php _e("Delete", "gravityformspaypalpro")?></a>
                                            </span>
                                        </div>
                                    </td>
                                    <td class="column-date">
                                        <?php
                                            switch($feed["meta"]["type"]){
                                                case "product" :
                                                    _e("Product and Services", "gravityformspaypalpro");
                                                break;

                                                case "donation" :
                                                    _e("Donation", "gravityformspaypalpro");
                                                break;

                                                case "subscription" :
                                                    _e("Subscription", "gravityformspaypalpro");
                                                break;
                                            }
                                        ?>
                                    </td>
                                </tr>
                                <?php
                            }
                        }
                        else{
                            ?>
                            <tr>
                                <td colspan="4" style="padding:20px;">
                                    <?php echo sprintf(__("You don't have any PayPal Pro feeds configured. Let's go %screate one%s!", "gravityformspaypalpro"), '<a href="admin.php?page=gf_paypalpro&view=edit&id=0">', "</a>"); ?>
                                </td>
                            </tr>
                            <?php
                        }
                        ?>
                    </tbody>
                </table>
            </form>
        </div>
        <script type="text/javascript">
            function DeleteSetting(id){
                jQuery("#action_argument").val(id);
                jQuery("#action").val("delete");
                jQuery("#feed_form")[0].submit();
            }
            function ToggleActive(img, feed_id){
                var is_active = img.src.indexOf("active1.png") >=0
                if(is_active){
                    img.src = img.src.replace("active1.png", "active0.png");
                    jQuery(img).attr('title','<?php _e("Inactive", "gravityformspaypalpro") ?>').attr('alt', '<?php _e("Inactive", "gravityformspaypalpro") ?>');
                }
                else{
                    img.src = img.src.replace("active0.png", "active1.png");
                    jQuery(img).attr('title','<?php _e("Active", "gravityformspaypalpro") ?>').attr('alt', '<?php _e("Active", "gravityformspaypalpro") ?>');
                }

                var mysack = new sack(ajaxurl);
                mysack.execute = 1;
                mysack.method = 'POST';
                mysack.setVar( "action", "gf_paypalpro_update_feed_active" );
                mysack.setVar( "gf_paypalpro_update_feed_active", "<?php echo wp_create_nonce("gf_paypalpro_update_feed_active") ?>" );
                mysack.setVar( "feed_id", feed_id );
                mysack.setVar( "is_active", is_active ? 0 : 1 );
                mysack.encVar( "cookie", document.cookie, false );
                mysack.onError = function() { alert('<?php _e("Ajax error while updating feed", "gravityformspaypalpro" ) ?>' )};
                mysack.runAJAX();

                return true;
            }


        </script>
        <?php
    }

    public static function settings_page(){

        if(rgpost("uninstall")){
            check_admin_referer("uninstall", "gf_paypalpro_uninstall");
            self::uninstall();

            ?>
            <div class="updated fade" style="padding:20px;"><?php _e(sprintf("Gravity Forms PayPal Pro Add-On have been successfully uninstalled. It can be re-activated from the %splugins page%s.", "<a href='plugins.php'>","</a>"), "gravityformspaypalpro")?></div>
            <?php
            return;
        }
        else if(isset($_POST["gf_paypalpro_submit"])){
            check_admin_referer("update", "gf_paypalpro_update");
            $settings = array(  "username" => $_POST["gf_paypalpro_username"],
                                "password" => $_POST["gf_paypalpro_password"],
                                "signature" => $_POST["gf_paypalpro_signature"],
                                "mode" => $_POST["gf_paypalpro_mode"],
                                "ipn_configured" => rgpost("gf_ipn_configured"));
            update_option("gf_paypalpro_settings", $settings);
        }
        else{
            $settings = get_option("gf_paypalpro_settings");
        }

        self::log_debug("Validating credentials.");
        $is_valid = self::is_valid_key();

        $message = "";
        if($is_valid)
            $message = __("Valid PayPal Pro credentials.", "gravityformspaypalpro");
        else if(!empty($settings["username"]))
            $message = __("Invalid PayPal Pro credentials.", "gravityformspaypalpro");

		self::log_debug("Credential status: {$message}");
        ?>

        <style>
            .valid_credentials{color:green;}
            .invalid_credentials{color:red;}
            .size-1{width:400px;}
        </style>

        <form action="" method="post">
            <?php wp_nonce_field("update", "gf_paypalpro_update") ?>

            <table class="form-table">
                <tr>
                    <td colspan="2">
                        <h3><?php _e("PayPal Pro Settings", "gravityformspaypalpro") ?></h3>
                        <p style="text-align: left;">
                            <?php _e(sprintf("PayPal Pro is a merchant account and gateway in one. Use Gravity Forms to collect payment information and automatically integrate to your PayPal Pro account. If you don't have a PayPal Pro account, you can %ssign up for one here%s", "<a href='https://registration.paypal.com/welcomePage.do?bundleCode=C3&country=US&partner=PayPal' target='_blank'>" , "</a>"), "gravityformspaypalpro") ?>
                        </p>
                    </td>
                </tr>

                <tr>
                    <th scope="row" nowrap="nowrap"><label for="gf_paypalpro_mode"><?php _e("API", "gravityformspaypalpro"); ?></label> </th>
                    <td width="88%">
                        <input type="radio" name="gf_paypalpro_mode" id="gf_paypalpro_mode_production" value="production" <?php echo rgar($settings, 'mode') != "test" ? "checked='checked'" : "" ?>/>
                        <label class="inline" for="gf_paypalpro_mode_production"><?php _e("Live", "gravityformspaypalpro"); ?></label>
                        &nbsp;&nbsp;&nbsp;
                        <input type="radio" name="gf_paypalpro_mode" id="gf_paypalpro_mode_test" value="test" <?php echo rgar($settings, 'mode') == "test" ? "checked='checked'" : "" ?>/>
                        <label class="inline" for="gf_paypalpro_mode_test"><?php _e("Sandbox", "gravityformspaypalpro"); ?></label>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><label for="gf_paypalpro_username"><?php _e("API Username", "gravityformspaypalpro"); ?></label> </th>
                    <td width="88%">
                        <input class="size-1" id="gf_paypalpro_username" name="gf_paypalpro_username" value="<?php echo esc_attr($settings["username"]) ?>" />
                        <img src="<?php echo self::get_base_url() ?>/images/<?php echo $is_valid ? "tick.png" : "stop.png" ?>" border="0" alt="<?php $message ?>" title="<?php echo $message ?>" style="display:<?php echo empty($message) ? 'none;' : 'inline;' ?>" />
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="gf_paypalpro_password"><?php _e("API Password", "gravityformspaypalpro"); ?></label> </th>
                    <td width="88%">
                        <input type="text" class="size-1" id="gf_paypalpro_password" name="gf_paypalpro_password" value="<?php echo esc_attr($settings["password"]) ?>" />
                        <img src="<?php echo self::get_base_url() ?>/images/<?php echo $is_valid ? "tick.png" : "stop.png" ?>" border="0" alt="<?php $message ?>" title="<?php echo $message ?>" style="display:<?php echo empty($message) ? 'none;' : 'inline;' ?>" />
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="gf_paypalpro_signature"><?php _e("Signature", "gravityformspaypalpro"); ?></label> </th>
                    <td width="88%">
                        <input type="text" class="size-1" id="gf_paypalpro_signature" name="gf_paypalpro_signature" value="<?php echo esc_attr($settings["signature"]) ?>" />
                        <img src="<?php echo self::get_base_url() ?>/images/<?php echo $is_valid ? "tick.png" : "stop.png" ?>" border="0" alt="<?php $message ?>" title="<?php echo $message ?>" style="display:<?php echo empty($message) ? 'none;' : 'inline;' ?>" />
                    </td>
                </tr>

                <tr>
                    <td colspan="2">
                        <h3><?php _e("Recurring Payments Setup", "gravityformspaypalpro") ?></h3>
                        <p style="text-align: left;">
                            <?php _e("To create recurring payments, you must have Instant Payment Notification (IPN) setup in your PayPal Pro account. Follow the steps below and confirm IPN is enabled.", "gravityformspaypalpro") ?>
                        </p>
                        <ul>
                            <li><?php echo sprintf(__("Navigate to your PayPal %sIPN Settings page.%s", "gravityformspaypal"), "<a href='https://www.paypal.com/us/cgi-bin/webscr?cmd=_profile-ipn-notify' target='_blank'>" , "</a>") ?></li>
                            <li><?php _e("If IPN is already enabled, you will see your current IPN settings along with a button to turn off IPN. If that is the case, just check the confirmation box below and you are ready to go!", "gravityformspaypalpro") ?></li>
                            <li><?php _e("If IPN is not enabled, click the 'Choose IPN Settings' button.", "gravityformspaypal") ?></li>
                            <li><?php echo sprintf(__("Click the box to enable IPN and enter the following Notification URL: %s", "gravityformspaypal"), "<strong>" . esc_url( add_query_arg("page", "gf_paypalpro_ipn", get_bloginfo("url") . "/") )  . "</strong>") ?></li>
                        </ul>
                    </td>
                </tr>

                <tr>
                    <td colspan="2">
                        <input type="checkbox" name="gf_ipn_configured" id="gf_ipn_configured" <?php echo $settings["ipn_configured"] ? "checked='checked'" : ""?>/>
                        <label for="gf_ipn_configured" class="inline"><?php _e("IPN is setup in my PayPal Pro account.", "gravityformspaypalpro") ?></label>
                    </td>
                </tr>
                <tr>
                    <td colspan="2" ><input type="submit" name="gf_paypalpro_submit" class="button-primary" value="<?php _e("Save Settings", "gravityformspaypalpro") ?>" /></td>
                </tr>

            </table>

        </form>

        <form action="" method="post">
            <?php wp_nonce_field("uninstall", "gf_paypalpro_uninstall") ?>
            <?php if(GFCommon::current_user_can_any("gravityforms_paypalpro_uninstall")){ ?>
                <div class="hr-divider"></div>

                <div class="delete-alert alert_red">
                    <h3><i class="fa fa-exclamation-triangle gf_invalid"></i> Warning</h3>
                    
                    <div class="gf_delete_notice" "=""><strong><?php _e("This operation deletes ALL PayPal Pro feeds.", "gravityformspaypalpro") ?></strong><?php _e("If you continue, you will not be able to recover any PayPal Pro data.", "gravityformspaypalpro") ?>
                    </div>    

                    <input type="submit" name="uninstall" value="Uninstall PayPal Pro Add-on" class="button" onclick="return confirm('<?php _e("Warning! ALL settings will be deleted. This cannot be undone. \'OK\' to delete, \'Cancel\' to stop", "gravityformspaypalpro") ?>');">
                </div>
            <?php } ?>
        </form>
        <?php
    }

    private static function is_valid_key($local_api_settings = array()){

        if(!empty($local_api_settings))
            $response = self::post_to_paypal("GetBalance",null,$local_api_settings);
        else
            $response = self::post_to_paypal("GetBalance");

        if(!empty($response) && $response["ACK"] == "Success"){
            return true;
		}
        else{
            return false;
		}
    }

    private static function post_to_paypal($methodName, $nvp = array(), $local_api_settings = array(), $form = array(), $entry = array()) {

        // Set up your API credentials, PayPal end point, and API version.
        if(!empty($local_api_settings))
        {
            $API_UserName = urlencode($local_api_settings["username"]);
            $API_Password = urlencode($local_api_settings["password"]);
            $API_Signature = urlencode($local_api_settings["signature"]);
            $mode = $local_api_settings["mode"];
        }
        else
        {
            $API_UserName = urlencode(self::get_username());
            $API_Password = urlencode(self::get_password());
            $API_Signature = urlencode(self::get_signature());
            $mode = self::get_mode();
        }


        $version = urlencode('52.0');
        $API_Endpoint = $mode == "test" ? "https://api-3t.sandbox.paypal.com/nvp" : "https://api-3t.paypal.com/nvp";

        // Set the curl parameters.
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $API_Endpoint);
        curl_setopt($ch, CURLOPT_VERBOSE, 1);

        /***
         * Determines if the cURL CURLOPT_SSL_VERIFYPEER option is enabled.
         *
         * @since 1.7.2
         *
         * @param bool is_enabled True to enable peer verification. False to bypass peer verification. Defaults to true.
         */
        $verify_peer = apply_filters( 'gform_paypalpro_verifypeer', true );
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $verify_peer);

        /***
         * Determines if the cURL CURLOPT_SSL_VERIFYHOST option is enabled.
         *
         * @since 1.7.2
         *
         * @param bool is_enabled True to enable host verification. False to bypass host verification. Defaults to true.
         */
        $verify_host = apply_filters( 'gform_paypalpro_verifyhost', 2 );
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $verify_host);

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);

        $nvpstr = "";
        if(is_array($nvp)){
            foreach($nvp as $key => $value) {
                if (is_array($value)) {
                    foreach($value as $item) {
                        if (strlen($nvpstr) > 0) $nvpstr .= "&";
                        $nvpstr .= "$key=".urlencode($item);
                    }
                } else {
                    if (strlen($nvpstr) > 0) $nvpstr .= "&";
                    $nvpstr .= "$key=".urlencode($value);
                }
            }
        }


        //add the bn code (build notation code)
        $nvpstr = "BUTTONSOURCE=Rocketgenius_SP&$nvpstr";
        
        // Set the API operation, version, and API signature in the request.
        $nvpreq = "METHOD=$methodName&VERSION=$version&PWD=$API_Password&USER=$API_UserName&SIGNATURE=$API_Signature&$nvpstr";

        //only do this when certain methods
        if ($methodName == "CreateRecurringPaymentsProfile" || $methodName == "DoDirectPayment")
        {
        	//apply filter which allows the query string passed to be altered
        	$nvpreq = apply_filters("gform_paypalpro_query_{$form['id']}", apply_filters("gform_paypalpro_query", $nvpreq, $form, $entry), $form, $entry);
		}

        self::log_debug("Sending request to PayPal - URL: {$API_Endpoint} Request: {$nvpreq}");
        // Set the request as a POST FIELD for curl.
        curl_setopt($ch, CURLOPT_POSTFIELDS, $nvpreq);

        // Get response from the server.
        $httpResponse = curl_exec($ch);
        self::log_debug("Response from PayPal: " . $httpResponse);

        // Extract the response details.
        $httpParsedResponseAr = array();
        if($httpResponse)
        {
            $httpResponseAr = explode("&", $httpResponse);
            foreach ($httpResponseAr as $i => $value) {
                $tmpAr = explode("=", urldecode($value));
                if(sizeof($tmpAr) > 1) {
                    $httpParsedResponseAr[$tmpAr[0]] = $tmpAr[1];
                }
            }
        }
		self::log_debug("Friendly view of response: " . print_r($httpParsedResponseAr, true));
        return $httpParsedResponseAr;
}

    private static function get_username(){
        $settings = get_option("gf_paypalpro_settings");
        $username = $settings["username"];
        return $username;
    }

    private static function get_password(){
        $settings = get_option("gf_paypalpro_settings");
        $password = $settings["password"];
        return $password;
    }

    private static function get_signature(){
        $settings = get_option("gf_paypalpro_settings");
        $signature = $settings["signature"];
        return $signature;
    }

    private static function get_mode(){
        $settings = get_option("gf_paypalpro_settings");
        $mode = $settings["mode"];
        return $mode;
    }

    private static function get_local_api_settings($config){
        $local_api_settings = array("mode" => $config["meta"]["api_mode"], "username" => $config["meta"]["api_username"], "password" =>  $config["meta"]["api_password"], "signature" => $config["meta"]["api_signature"]);
        return $local_api_settings;
    }

    private static function get_product_field_options($productFields, $selectedValue){
        $options = "<option value=''>" . __("Select a product", "gravityformspaypalpro") . "</option>";
        foreach($productFields as $field){
            $label = GFCommon::truncate_middle($field["label"], 30);
            $selected = $selectedValue == $field["id"] ? "selected='selected'" : "";
            $options .= "<option value='{$field["id"]}' {$selected}>{$label}</option>";
        }

        return $options;
    }

    private static function stats_page(){
        ?>
        <style>
          .paypalpro_graph_container{clear:both; padding-left:5px; min-width:789px; margin-right:50px;}
        .paypalpro_message_container{clear: both; padding-left:5px; text-align:center; padding-top:120px; border: 1px solid #CCC; background-color: #FFF; width:100%; height:160px;}
        .paypalpro_summary_container {margin:30px 60px; text-align: center; min-width:740px; margin-left:50px;}
        .paypalpro_summary_item {width:160px; background-color: #FFF; border: 1px solid #CCC; padding:14px 8px; margin:6px 3px 6px 0; display: -moz-inline-stack; display: inline-block; zoom: 1; *display: inline; text-align:center;}
        .paypalpro_summary_value {font-size:20px; margin:5px 0; font-family:Georgia,"Times New Roman","Bitstream Charter",Times,serif}
        .paypalpro_summary_title {}
        #paypalpro_graph_tooltip {border:4px solid #b9b9b9; padding:11px 0 0 0; background-color: #f4f4f4; text-align:center; -moz-border-radius: 4px; -webkit-border-radius: 4px; border-radius: 4px; -khtml-border-radius: 4px;}
        #paypalpro_graph_tooltip .tooltip_tip {width:14px; height:14px; background-image:url(<?php echo self::get_base_url() ?>/images/tooltip_tip.png); background-repeat: no-repeat; position: absolute; bottom:-14px; left:68px;}

        .paypalpro_tooltip_date {line-height:130%; font-weight:bold; font-size:13px; color:#21759B;}
        .paypalpro_tooltip_sales {line-height:130%;}
        .paypalpro_tooltip_revenue {line-height:130%;}
            .paypalpro_tooltip_revenue .paypalpro_tooltip_heading {}
            .paypalpro_tooltip_revenue .paypalpro_tooltip_value {}
            .paypalpro_trial_disclaimer {clear:both; padding-top:20px; font-size:10px;}
        </style>
        <script type="text/javascript" src="<?php echo self::get_base_url() ?>/flot/jquery.flot.min.js"></script>
        <script type="text/javascript" src="<?php echo self::get_base_url() ?>/js/currency.js"></script>

        <div class="wrap">
            <img alt="<?php _e("PayPal Pro", "gravityformspaypalpro") ?>" style="margin: 15px 7px 0pt 0pt; float: left;" src="<?php echo self::get_base_url() ?>/images/paypal_wordpress_icon_32.png"/>
            <h2><?php _e("PayPal Pro Stats", "gravityformspaypalpro") ?></h2>

            <form method="post" action="">
                <ul class="subsubsub">
                    <li><a class="<?php echo (!RGForms::get("tab") || RGForms::get("tab") == "daily") ? "current" : "" ?>" href="?page=gf_paypalpro&view=stats&id=<?php echo absint( $_GET["id"] ) ?>"><?php _e("Daily", "gravityforms"); ?></a> | </li>
                    <li><a class="<?php echo RGForms::get("tab") == "weekly" ? "current" : ""?>" href="?page=gf_paypalpro&view=stats&id=<?php echo absint( $_GET["id"] ) ?>&tab=weekly"><?php _e("Weekly", "gravityforms"); ?></a> | </li>
                    <li><a class="<?php echo RGForms::get("tab") == "monthly" ? "current" : ""?>" href="?page=gf_paypalpro&view=stats&id=<?php echo absint( $_GET["id"] ) ?>&tab=monthly"><?php _e("Monthly", "gravityforms"); ?></a></li>
                </ul>
                <?php
                $config = GFPayPalProData::get_feed(RGForms::get("id"));

                switch(RGForms::get("tab")){
                    case "monthly" :
                        $chart_info = self::monthly_chart_info($config);
                    break;

                    case "weekly" :
                        $chart_info = self::weekly_chart_info($config);
                    break;

                    default :
                        $chart_info = self::daily_chart_info($config);
                    break;
                }

                if(!$chart_info["series"]){
                    ?>
                    <div class="paypalpro_message_container"><?php _e("No payments have been made yet.", "gravityformspaypalpro") ?> <?php echo $config["meta"]["trial_period_enabled"] && empty($config["meta"]["trial_amount"]) ? " **" : ""?></div>
                    <?php
                }
                else{
                    ?>
                    <div class="paypalpro_graph_container">
                        <div id="graph_placeholder" style="width:100%;height:300px;"></div>
                    </div>

                    <script type="text/javascript">
                        var paypalpro_graph_tooltips = <?php echo $chart_info["tooltips"] ?>;

                        jQuery.plot(jQuery("#graph_placeholder"), <?php echo $chart_info["series"] ?>, <?php echo $chart_info["options"] ?>);
                        jQuery(window).resize(function(){
                            jQuery.plot(jQuery("#graph_placeholder"), <?php echo $chart_info["series"] ?>, <?php echo $chart_info["options"] ?>);
                        });

                        var previousPoint = null;
                        jQuery("#graph_placeholder").bind("plothover", function (event, pos, item) {
                            startShowTooltip(item);
                        });

                        jQuery("#graph_placeholder").bind("plotclick", function (event, pos, item) {
                            startShowTooltip(item);
                        });

                        function startShowTooltip(item){
                            if (item) {
                                if (!previousPoint || previousPoint[0] != item.datapoint[0]) {
                                    previousPoint = item.datapoint;

                                    jQuery("#paypalpro_graph_tooltip").remove();
                                    var x = item.datapoint[0].toFixed(2),
                                        y = item.datapoint[1].toFixed(2);

                                    showTooltip(item.pageX, item.pageY, paypalpro_graph_tooltips[item.dataIndex]);
                                }
                            }
                            else {
                                jQuery("#paypalpro_graph_tooltip").remove();
                                previousPoint = null;
                            }
                        }

                        function showTooltip(x, y, contents) {
                            jQuery('<div id="paypalpro_graph_tooltip">' + contents + '<div class="tooltip_tip"></div></div>').css( {
                                position: 'absolute',
                                display: 'none',
                                opacity: 0.90,
                                width:'150px',
                                height:'<?php echo $config["meta"]["type"] == "subscription" ? "75px" : "60px" ;?>',
                                top: y - <?php echo $config["meta"]["type"] == "subscription" ? "100" : "89" ;?>,
                                left: x - 79
                            }).appendTo("body").fadeIn(200);
                        }


                        function convertToMoney(number){
                            var currency = getCurrentCurrency();
                            return currency.toMoney(number);
                        }
                        function formatWeeks(number){
                            number = number + "";
                            return "<?php _e("Week ", "gravityformspaypalpro") ?>" + number.substring(number.length-2);
                        }

                        function getCurrentCurrency(){
                            <?php
                            if(!class_exists("RGCurrency"))
                                require_once(ABSPATH . "/" . PLUGINDIR . "/gravityforms/currency.php");

                            $current_currency = RGCurrency::get_currency(GFCommon::get_currency());
                            ?>
                            var currency = new Currency(<?php echo GFCommon::json_encode($current_currency)?>);
                            return currency;
                        }
                    </script>
                <?php
                }
                $transaction_totals = GFPayPalProData::get_transaction_totals($config);

                switch($config["meta"]["type"]){
                    case "product" :
                        $total_sales = $transaction_totals["orders"];
                        $sales_label = __("Total Orders", "gravityformspaypalpro");
                    break;

                    case "donation" :
                        $total_sales = $transaction_totals["orders"];
                        $sales_label = __("Total Donations", "gravityformspaypalpro");
                    break;

                    case "subscription" :
                        $payment_totals = RGFormsModel::get_form_payment_totals($config["form_id"]);
                        $total_sales = $payment_totals["active"];
                        $sales_label = __("Active Subscriptions", "gravityformspaypalpro");
                    break;
                }

                $total_revenue = empty($transaction_totals["revenue"]) ? 0 : $transaction_totals["revenue"];
                ?>
                <div class="paypalpro_summary_container">
                    <div class="paypalpro_summary_item">
                        <div class="paypalpro_summary_title"><?php _e("Total Revenue", "gravityformspaypalpro")?></div>
                        <div class="paypalpro_summary_value"><?php echo GFCommon::to_money($total_revenue) ?></div>
                    </div>
                    <div class="paypalpro_summary_item">
                        <div class="paypalpro_summary_title"><?php echo $chart_info["revenue_label"]?></div>
                        <div class="paypalpro_summary_value"><?php echo $chart_info["revenue"] ?></div>
                    </div>
                    <div class="paypalpro_summary_item">
                        <div class="paypalpro_summary_title"><?php echo $sales_label?></div>
                        <div class="paypalpro_summary_value"><?php echo $total_sales ?></div>
                    </div>
                    <div class="paypalpro_summary_item">
                        <div class="paypalpro_summary_title"><?php echo $chart_info["sales_label"] ?></div>
                        <div class="paypalpro_summary_value"><?php echo $chart_info["sales"] ?></div>
                    </div>
                </div>
                <?php
                if(!$chart_info["series"] && $config["meta"]["trial_period_enabled"] && empty($config["meta"]["trial_amount"])){
                    ?>
                    <div class="paypalpro_trial_disclaimer"><?php _e("** Free trial transactions will only be reflected in the graph after the first payment is made (i.e. after trial period ends)", "gravityformspaypalpro") ?></div>
                    <?php
                }
                ?>
            </form>
        </div>
        <?php
    }

    private static function get_graph_timestamp($local_datetime){
        $local_timestamp = mysql2date("G", $local_datetime); //getting timestamp with timezone adjusted
        $local_date_timestamp = mysql2date("G", gmdate("Y-m-d 23:59:59", $local_timestamp)); //setting time portion of date to midnight (to match the way Javascript handles dates)
        $timestamp = ($local_date_timestamp - (24 * 60 * 60) + 1) * 1000; //adjusting timestamp for Javascript (subtracting a day and transforming it to milliseconds
        return $timestamp;
    }

    private static function matches_current_date($format, $js_timestamp){
        $target_date = $format == "YW" ? $js_timestamp : date($format, $js_timestamp / 1000);

        $current_date = gmdate($format, GFCommon::get_local_timestamp(time()));
        return $target_date == $current_date;
    }

    private static function daily_chart_info($config){
        global $wpdb;

		// Get entry table names and entry ID column.
		$entry_table      = self::get_entry_table_name();
		$entry_meta_table = self::get_entry_meta_table_name();
		$entry_id_column  = version_compare( self::get_gravityforms_db_version(), '2.3-dev-1', '<' ) ? 'lead_id' : 'entry_id';

        $tz_offset = self::get_mysql_tz_offset();
        $new_sales = $config["meta"]["type"] == "subscription" ? "t.transaction_type='signup'" : "is_renewal=0";
        $results = $wpdb->get_results("SELECT CONVERT_TZ(t.date_created, '+00:00', '" . $tz_offset . "') as date, sum(t.amount) as amount_sold, sum(is_renewal and t.transaction_type='payment') as renewals, sum({$new_sales}) as new_sales
                                        FROM {$entry_table} l
                                        INNER JOIN {$wpdb->prefix}rg_paypalpro_transaction t ON l.id = t.entry_id
                                        INNER JOIN {$entry_meta_table} m ON meta_key='paypalpro_feed_id' AND m.{$entry_id_column} = l.id
                                        WHERE m.meta_value='{$config["id"]}'
                                        GROUP BY date(date)
                                        ORDER BY payment_date desc
                                        LIMIT 30");

        $sales_today = 0;
        $revenue_today = 0;
        $tooltips = "";

        if(!empty($results)){

            $data = "[";

            foreach($results as $result){
                $timestamp = self::get_graph_timestamp($result->date);
                if(self::matches_current_date("Y-m-d", $timestamp)){
                    $sales_today += $result->new_sales;
                    $revenue_today += $result->amount_sold;
                }
                $data .="[{$timestamp},{$result->amount_sold}],";

                if($config["meta"]["type"] == "subscription"){
                    $sales_line = " <div class='paypalpro_tooltip_subscription'><span class='paypalpro_tooltip_heading'>" . __("New Subscriptions", "gravityformspaypalpro") . ": </span><span class='paypalpro_tooltip_value'>" . $result->new_sales . "</span></div><div class='paypalpro_tooltip_subscription'><span class='paypalpro_tooltip_heading'>" . __("Renewals", "gravityformspaypalpro") . ": </span><span class='paypalpro_tooltip_value'>" . $result->renewals . "</span></div>";
                }
                else{
                    $sales_line = "<div class='paypalpro_tooltip_sales'><span class='paypalpro_tooltip_heading'>" . __("Orders", "gravityformspaypalpro") . ": </span><span class='paypalpro_tooltip_value'>" . $result->new_sales . "</span></div>";
                }

                $tooltips .= "\"<div class='paypalpro_tooltip_date'>" . GFCommon::format_date($result->date, false, "", false) . "</div>{$sales_line}<div class='paypalpro_tooltip_revenue'><span class='paypalpro_tooltip_heading'>" . __("Revenue", "gravityformspaypalpro") . ": </span><span class='paypalpro_tooltip_value'>" . GFCommon::to_money($result->amount_sold) . "</span></div>\",";
            }
            $data = substr($data, 0, strlen($data)-1);
            $tooltips = substr($tooltips, 0, strlen($tooltips)-1);
            $data .="]";

            $series = "[{data:" . $data . "}]";
            $month_names = self::get_chart_month_names();
            $options ="
            {
                xaxis: {mode: 'time', monthnames: $month_names, timeformat: '%b %d', minTickSize:[1, 'day']},
                yaxis: {tickFormatter: convertToMoney},
                bars: {show:true, align:'right', barWidth: (24 * 60 * 60 * 1000) - 10000000},
                colors: ['#a3bcd3', '#14568a'],
                grid: {hoverable: true, clickable: true, tickColor: '#F1F1F1', backgroundColor:'#FFF', borderWidth: 1, borderColor: '#CCC'}
            }";
        }
        switch($config["meta"]["type"]){
            case "product" :
                $sales_label = __("Orders Today", "gravityformspaypalpro");
            break;

            case "donation" :
                $sales_label = __("Donations Today", "gravityformspaypalpro");
            break;

            case "subscription" :
                $sales_label = __("Subscriptions Today", "gravityformspaypalpro");
            break;
        }
        $revenue_today = GFCommon::to_money($revenue_today);
        return array("series" => $series, "options" => $options, "tooltips" => "[$tooltips]", "revenue_label" => __("Revenue Today", "gravityformspaypalpro"), "revenue" => $revenue_today, "sales_label" => $sales_label, "sales" => $sales_today);
    }

    private static function weekly_chart_info($config){
            global $wpdb;

            // Get entry table names and entry ID column.
            $entry_table      = self::get_entry_table_name();
            $entry_meta_table = self::get_entry_meta_table_name();
            $entry_id_column  = version_compare( self::get_gravityforms_db_version(), '2.3-dev-1', '<' ) ? 'lead_id' : 'entry_id';

            $tz_offset = self::get_mysql_tz_offset();
            $new_sales = $config["meta"]["type"] == "subscription" ? "t.transaction_type='signup'" : "is_renewal=0";
            $results = $wpdb->get_results("SELECT yearweek(CONVERT_TZ(t.date_created, '+00:00', '" . $tz_offset . "')) week_number, sum(t.amount) as amount_sold, sum(is_renewal and t.transaction_type='payment') as renewals, sum({$new_sales}) as new_sales
                                            FROM {$entry_table} l
                                            INNER JOIN {$wpdb->prefix}rg_paypalpro_transaction t ON l.id = t.entry_id
                                            INNER JOIN {$entry_meta_table} m ON meta_key='paypalpro_feed_id' AND m.{$entry_id_column} = l.id
                                            WHERE m.meta_value='{$config["id"]}'
                                            GROUP BY week_number
                                            ORDER BY week_number desc
                                            LIMIT 30");
            $sales_week = 0;
            $revenue_week = 0;
            $tooltips = "";
            if(!empty($results))
            {
                $data = "[";

                foreach($results as $result){
                    if(self::matches_current_date("YW", $result->week_number)){
                        $sales_week += $result->new_sales;
                        $revenue_week += $result->amount_sold;
                    }
                    $data .="[{$result->week_number},{$result->amount_sold}],";

                    if($config["meta"]["type"] == "subscription"){
                        $sales_line = " <div class='paypalpro_tooltip_subscription'><span class='paypalpro_tooltip_heading'>" . __("New Subscriptions", "gravityformspaypalpro") . ": </span><span class='paypalpro_tooltip_value'>" . $result->new_sales . "</span></div><div class='paypalpro_tooltip_subscription'><span class='paypalpro_tooltip_heading'>" . __("Renewals", "gravityformspaypalpro") . ": </span><span class='paypalpro_tooltip_value'>" . $result->renewals . "</span></div>";
                    }
                    else{
                        $sales_line = "<div class='paypalpro_tooltip_sales'><span class='paypalpro_tooltip_heading'>" . __("Orders", "gravityformspaypalpro") . ": </span><span class='paypalpro_tooltip_value'>" . $result->new_sales . "</span></div>";
                    }

                    $tooltips .= "\"<div class='paypalpro_tooltip_date'>" . substr($result->week_number, 0, 4) . ", " . __("Week",  "gravityformspaypalpro") . " " . substr($result->week_number, strlen($result->week_number)-2, 2) . "</div>{$sales_line}<div class='paypalpro_tooltip_revenue'><span class='paypalpro_tooltip_heading'>" . __("Revenue", "gravityformspaypalpro") . ": </span><span class='paypalpro_tooltip_value'>" . GFCommon::to_money($result->amount_sold) . "</span></div>\",";
                }
                $data = substr($data, 0, strlen($data)-1);
                $tooltips = substr($tooltips, 0, strlen($tooltips)-1);
                $data .="]";

                $series = "[{data:" . $data . "}]";
                $month_names = self::get_chart_month_names();
                $options ="
                {
                    xaxis: {tickFormatter: formatWeeks, tickDecimals: 0},
                    yaxis: {tickFormatter: convertToMoney},
                    bars: {show:true, align:'center', barWidth:0.95},
                    colors: ['#a3bcd3', '#14568a'],
                    grid: {hoverable: true, clickable: true, tickColor: '#F1F1F1', backgroundColor:'#FFF', borderWidth: 1, borderColor: '#CCC'}
                }";
            }

            switch($config["meta"]["type"]){
                case "product" :
                    $sales_label = __("Orders this Week", "gravityformspaypalpro");
                break;

                case "donation" :
                    $sales_label = __("Donations this Week", "gravityformspaypalpro");
                break;

                case "subscription" :
                    $sales_label = __("Subscriptions this Week", "gravityformspaypalpro");
                break;
            }
            $revenue_week = GFCommon::to_money($revenue_week);

            return array("series" => $series, "options" => $options, "tooltips" => "[$tooltips]", "revenue_label" => __("Revenue this Week", "gravityformspaypalpro"), "revenue" => $revenue_week, "sales_label" => $sales_label , "sales" => $sales_week);
    }

    private static function monthly_chart_info($config){
            global $wpdb;

            // Get entry table names and entry ID column.
            $entry_table      = self::get_entry_table_name();
            $entry_meta_table = self::get_entry_meta_table_name();
            $entry_id_column  = version_compare( self::get_gravityforms_db_version(), '2.3-dev-1', '<' ) ? 'lead_id' : 'entry_id';

    		$tz_offset = self::get_mysql_tz_offset();
            $new_sales = $config["meta"]["type"] == "subscription" ? "t.transaction_type='signup'" : "is_renewal=0";
            $results = $wpdb->get_results("SELECT date_format(CONVERT_TZ(t.date_created, '+00:00', '" . $tz_offset . "'), '%Y-%m-02') date, sum(t.amount) as amount_sold, sum(is_renewal and t.transaction_type='payment') as renewals, sum({$new_sales}) as new_sales
                                            FROM {$entry_table} l
                                            INNER JOIN {$wpdb->prefix}rg_paypalpro_transaction t ON l.id = t.entry_id
                                            INNER JOIN {$entry_meta_table} m ON meta_key='paypalpro_feed_id' AND m.{$entry_id_column} = l.id
                                            WHERE m.meta_value='{$config["id"]}'
                                            group by date
                                            order by date desc
                                            LIMIT 30");

            $sales_month = 0;
            $revenue_month = 0;
            $tooltips = "";
            if(!empty($results)){

                $data = "[";

                foreach($results as $result){
                    $timestamp = self::get_graph_timestamp($result->date);
                    if(self::matches_current_date("Y-m", $timestamp)){
                        $sales_month += $result->new_sales;
                        $revenue_month += $result->amount_sold;
                    }
                    $data .="[{$timestamp},{$result->amount_sold}],";

                    if($config["meta"]["type"] == "subscription"){
                        $sales_line = " <div class='paypalpro_tooltip_subscription'><span class='paypalpro_tooltip_heading'>" . __("New Subscriptions", "gravityformspaypalpro") . ": </span><span class='paypalpro_tooltip_value'>" . $result->new_sales . "</span></div><div class='paypalpro_tooltip_subscription'><span class='paypalpro_tooltip_heading'>" . __("Renewals", "gravityformspaypalpro") . ": </span><span class='paypalpro_tooltip_value'>" . $result->renewals . "</span></div>";
                    }
                    else{
                        $sales_line = "<div class='paypalpro_tooltip_sales'><span class='paypalpro_tooltip_heading'>" . __("Orders", "gravityformspaypalpro") . ": </span><span class='paypalpro_tooltip_value'>" . $result->new_sales . "</span></div>";
                    }

                    $tooltips .= "\"<div class='paypalpro_tooltip_date'>" . GFCommon::format_date($result->date, false, "F, Y", false) . "</div>{$sales_line}<div class='paypalpro_tooltip_revenue'><span class='paypalpro_tooltip_heading'>" . __("Revenue", "gravityformspaypalpro") . ": </span><span class='paypalpro_tooltip_value'>" . GFCommon::to_money($result->amount_sold) . "</span></div>\",";
                }
                $data = substr($data, 0, strlen($data)-1);
                $tooltips = substr($tooltips, 0, strlen($tooltips)-1);
                $data .="]";

                $series = "[{data:" . $data . "}]";
                $month_names = self::get_chart_month_names();
                $options ="
                {
                    xaxis: {mode: 'time', monthnames: $month_names, timeformat: '%b %y', minTickSize: [1, 'month']},
                    yaxis: {tickFormatter: convertToMoney},
                    bars: {show:true, align:'center', barWidth: (24 * 60 * 60 * 30 * 1000) - 130000000},
                    colors: ['#a3bcd3', '#14568a'],
                    grid: {hoverable: true, clickable: true, tickColor: '#F1F1F1', backgroundColor:'#FFF', borderWidth: 1, borderColor: '#CCC'}
                }";
            }
            switch($config["meta"]["type"]){
                case "product" :
                    $sales_label = __("Orders this Month", "gravityformspaypalpro");
                break;

                case "donation" :
                    $sales_label = __("Donations this Month", "gravityformspaypalpro");
                break;

                case "subscription" :
                    $sales_label = __("Subscriptions this Month", "gravityformspaypalpro");
                break;
            }
            $revenue_month = GFCommon::to_money($revenue_month);
            return array("series" => $series, "options" => $options, "tooltips" => "[$tooltips]", "revenue_label" => __("Revenue this Month", "gravityformspaypalpro"), "revenue" => $revenue_month, "sales_label" => $sales_label, "sales" => $sales_month);
    }

    private static function get_mysql_tz_offset(){
        $tz_offset = get_option("gmt_offset");

        //add + if offset starts with a number
        if(is_numeric(substr($tz_offset, 0, 1)))
            $tz_offset = "+" . $tz_offset;

        return $tz_offset . ":00";
    }

    private static function get_chart_month_names(){
        return "['" . __("Jan", "gravityformspaypalpro") ."','" . __("Feb", "gravityformspaypalpro") ."','" . __("Mar", "gravityformspaypalpro") ."','" . __("Apr", "gravityformspaypalpro") ."','" . __("May", "gravityformspaypalpro") ."','" . __("Jun", "gravityformspaypalpro") ."','" . __("Jul", "gravityformspaypalpro") ."','" . __("Aug", "gravityformspaypalpro") ."','" . __("Sep", "gravityformspaypalpro") ."','" . __("Oct", "gravityformspaypalpro") ."','" . __("Nov", "gravityformspaypalpro") ."','" . __("Dec", "gravityformspaypalpro") ."']";
    }

    public static function paypalpro_entry_info($form_id, $lead) {

        // adding cancel subscription button and script to entry info section
        $lead_id = $lead["id"];
        $payment_status = $lead["payment_status"];
        $transaction_type = $lead["transaction_type"];
        $gateway = gform_get_meta($lead_id, "payment_gateway");
        $cancelsub_button = "";

        if($transaction_type == 2 && $payment_status <> "Canceled" && $gateway == "paypalpro")
        {
            $cancelsub_button .= '<input id="cancelsub" type="button" name="cancelsub" value="' . __("Cancel Subscription", "gravityformspaypalpro") . '" class="button" onclick=" if( confirm(\'' . __("Warning! This subscription will be canceled. This cannot be undone. \'OK\' to cancel subscription, \'Cancel\' to stop", "gravityformspaypalpro") . '\')){cancel_paypalpro_subscription();};"/>';

            $cancelsub_button .= '<img src="'. GFPayPalPro::get_base_url() . '/images/loading.gif" id="paypalpro_wait" style="display: none;"/>';

            $cancelsub_button .= '<script type="text/javascript">
                function cancel_paypalpro_subscription(){
                    jQuery("#paypalpro_wait").show();
                    jQuery("#cancelsub").attr("disabled", true);
                    var lead_id = ' . $lead_id  .'
                    jQuery.post(ajaxurl, {
                            action:"gf_cancel_paypalpro_subscription",
                            leadid:lead_id,
                            gf_cancel_pp_subscription: "' . wp_create_nonce('gf_cancel_pp_subscription') . '"},
                            function(response){

                                jQuery("#paypalpro_wait").hide();

                                if(response == "1")
                                {
                                    jQuery("#gform_payment_status").html("' . __("Canceled", "gravityformspaypalpro") . '");
                                    jQuery("#cancelsub").hide();
                                }
                                else
                                {
                                    jQuery("#cancelsub").attr("disabled", false);
                                    alert("' . __("The subscription could not be canceled. Please try again later.") . '");
                                }
                            }
                            );
                }
            </script>';

            echo $cancelsub_button;
        }
    }

    public static function cancel_paypalpro_subscription() {
        check_ajax_referer("gf_cancel_pp_subscription","gf_cancel_pp_subscription");

        $lead_id = $_POST["leadid"];
        $lead = RGFormsModel::get_lead($lead_id);

        //Getting feed config
        $form = RGFormsModel::get_form_meta($lead["form_id"]);
        $config = self::get_config_by_entry($lead_id);

        // Determine if feed specific api settings are enabled
        $local_api_settings = array();
        if($config["meta"]["api_settings_enabled"] == 1)
        {
             $local_api_settings = self::get_local_api_settings($config);
        }

        $args = array("PROFILEID" => $lead["transaction_id"], "ACTION" => "Cancel");
        self::log_debug("Canceling subscription.");
        $response = self::post_to_paypal("ManageRecurringPaymentsProfileStatus", $args, $local_api_settings);
        if(!empty($response) && $response["ACK"] == "Success"){
            self::cancel_subscription($lead);
            self::log_debug("Subscription canceled.");
            die("1");
        }
        else{
        	self::log_error("Unable to cancel subscription.");
            die("0");
        }
    }

    private static function cancel_subscription($lead){

        $lead["payment_status"] = "Canceled";
        GFAPI::update_entry($lead);

        $config = self::get_config_by_entry($lead["id"]);
        if(!$config)
            return;

        //1- delete post or mark it as a draft based on configuration
        if(rgars($config, "meta/update_post_action") == "draft" && !rgempty("post_id", $lead)){
            $post = get_post($lead["post_id"]);
            $post->post_status = 'draft';
            wp_update_post($post);
        }
        else if(rgars($config, "meta/update_post_action") == "delete" && !rgempty("post_id", $lead)){
            wp_delete_post($lead["post_id"]);
        }

        //2- call subscription canceled hook
        do_action("gform_subscription_canceled", $lead, $config, $lead["transaction_id"], "paypalpro");

    }

    // Edit Page
    private static function edit_page(){
        ?>
        <style>
            #paypalpro_submit_container{clear:both;}
            .paypalpro_col_heading{padding-bottom:2px; border-bottom: 1px solid #ccc; font-weight:bold; width:120px;}
            .paypalpro_field_cell {padding: 6px 17px 0 0; margin-right:15px;}

            .paypalpro_validation_error{ background-color:#FFDFDF; margin-top:4px; margin-bottom:6px; padding-top:6px; padding-bottom:6px; border:1px dotted #C89797;}
            .paypalpro_validation_error span {color: red;}
            .left_header{float:left; width:200px;}
            .margin_vertical_10{margin: 10px 0; padding-left:5px;}
            .margin_vertical_30{margin: 30px 0; padding-left:5px;}
            .width-1{width:300px;}
            .gf_paypalpro_invalid_form{margin-top:30px; background-color:#FFEBE8;border:1px solid #CC0000; padding:10px; width:600px;}
            .size-1{width:400px;}
        </style>
        <script type="text/javascript">
            var form = Array();
            function ToggleSetupFee(){
                if(jQuery('#gf_paypalpro_setup_fee').is(':checked')){
                    jQuery('#paypalpro_setup_fee_container').show('slow');
                    jQuery('#paypalpro_enable_trial_container, #paypalpro_trial_period_container').slideUp();
                }
                else{
                    jQuery('#paypalpro_setup_fee_container').hide('slow');
                    jQuery('#paypalpro_enable_trial_container').slideDown();
                    ToggleTrial();
                }
            }

            function ToggleTrial(){
                if(jQuery('#gf_paypalpro_trial_period').is(':checked'))
                    jQuery('#paypalpro_trial_period_container').show('slow');
                else
                    jQuery('#paypalpro_trial_period_container').hide('slow');
            }
        </script>
        <div class="wrap">
            <img alt="<?php _e("PayPal Pro", "gravityformspaypalpro") ?>" style="margin: 15px 7px 0pt 0pt; float: left;" src="<?php echo self::get_base_url() ?>/images/paypal_wordpress_icon_32.png"/>
            <h2><?php _e("PayPal Pro Transaction Settings", "gravityformspaypalpro") ?></h2>

        <?php

        //getting setting id (0 when creating a new one)
        $id = !empty($_POST["paypalpro_setting_id"]) ? $_POST["paypalpro_setting_id"] : absint($_GET["id"]);
        $config = empty($id) ? array("meta" => array(), "is_active" => true) : GFPayPalProData::get_feed($id);
        $is_validation_error = false;

        //updating meta information
        if(rgpost("gf_paypalpro_submit")){

            $config["form_id"] = absint(rgpost("gf_paypalpro_form"));
            $config["meta"]["type"] = rgpost("gf_paypalpro_type");
            $config["meta"]["disable_note"] = rgpost("gf_paypalpro_disable_note");
            $config["meta"]["disable_shipping"] = rgpost('gf_paypalpro_disable_shipping');
            //$config["meta"]["delay_autoresponder"] = rgpost('gf_paypalpro_delay_autoresponder');
            //$config["meta"]["delay_notification"] = rgpost('gf_paypalpro_delay_notification');
            //$config["meta"]["delay_post"] = rgpost('gf_paypalpro_delay_post');
            $config["meta"]["update_post_action"] = rgpost('gf_paypalpro_update_action');

            // paypalpro conditional
            $config["meta"]["paypalpro_conditional_enabled"] = rgpost('gf_paypalpro_conditional_enabled');
            $config["meta"]["paypalpro_conditional_field_id"] = rgpost('gf_paypalpro_conditional_field_id');
            $config["meta"]["paypalpro_conditional_operator"] = rgpost('gf_paypalpro_conditional_operator');
            $config["meta"]["paypalpro_conditional_value"] = rgpost('gf_paypalpro_conditional_value');

            //recurring fields
            $config["meta"]["recurring_amount_field"] = rgpost("gf_paypalpro_recurring_amount");
            $config["meta"]["billing_cycle_number"] = rgpost("gf_paypalpro_billing_cycle_number");
            $config["meta"]["billing_cycle_type"] = rgpost("gf_paypalpro_billing_cycle_type");
            $config["meta"]["recurring_times"] = rgpost("gf_paypalpro_recurring_times");
            $config["meta"]["setup_fee_enabled"] = rgpost('gf_paypalpro_setup_fee');
            $config["meta"]["setup_fee_amount_field"] = rgpost('gf_paypalpro_setup_fee_amount');
            $has_setup_fee = $config["meta"]["setup_fee_enabled"];
            $config["meta"]["trial_period_enabled"] = $has_setup_fee ? false : rgpost('gf_paypalpro_trial_period');
            $config["meta"]["trial_type"] = $has_setup_fee ? "" : rgpost('gf_paypalpro_trial_type');
            $config["meta"]["trial_amount_field"] = $has_setup_fee ? "" : rgpost('gf_paypalpro_trial_amount');
            $config["meta"]["trial_period_number"] = $has_setup_fee ? "" : rgpost('gf_paypalpro_trial_period_number');
            $config["meta"]["trial_period_type"] = $has_setup_fee ? "" : rgpost('gf_paypalpro_trial_period_type');
            $config["meta"]["trial_recurring_times"] = $has_setup_fee ? "" : 1; //rgpost("gf_paypalpro_trial_recurring_times");

            //api settings fields
            $config["meta"]["api_settings_enabled"] = rgpost('gf_paypalpro_api_settings');
            $config["meta"]["api_mode"] = rgpost('gf_paypalpro_api_mode');
            $config["meta"]["api_username"] = rgpost('gf_paypalpro_api_username');
            $config["meta"]["api_password"] = rgpost('gf_paypalpro_api_password');
            $config["meta"]["api_signature"] = rgpost('gf_paypalpro_api_signature');

            if(!empty($config["meta"]["api_settings_enabled"]))
            {

                $local_api_settings = self::get_local_api_settings($config);
                self::log_debug("Validating credentials.");
                $is_valid = self::is_valid_key($local_api_settings);
                if($is_valid)
                {
                    $config["meta"]["api_valid"] = true;
                    $config["meta"]["api_message"] = "Valid PayPal Pro credentials.";
                    self::log_debug($config["meta"]["api_message"]);
                }
                else
                {
                    $config["meta"]["api_valid"] = false;
                    $config["meta"]["api_message"] = "Invalid PayPal Pro credentials.";
                    self::log_error($config["meta"]["api_message"]);
                }
            }

            $customer_fields = self::get_customer_fields();
            $config["meta"]["customer_fields"] = array();
            foreach($customer_fields as $field){
                $config["meta"]["customer_fields"][$field["name"]] = $_POST["paypalpro_customer_field_{$field["name"]}"];
            }

            $config = apply_filters('gform_paypalpro_save_config', $config);

            $is_validation_error = apply_filters("gform_paypalpro_config_validation", false, $config);

            if(!$is_validation_error){
                $id = GFPayPalProData::update_feed($id, $config["form_id"], $config["is_active"], $config["meta"]);
                ?>
                <div class="updated fade" style="padding:6px"><?php echo sprintf(__("Feed Updated. %sback to list%s", "gravityformspaypalpro"), "<a href='?page=gf_paypalpro'>", "</a>") ?></div>
                <?php
            }
            else{
                $is_validation_error = true;
            }

        }

        $form = isset($config["form_id"]) && $config["form_id"] ? $form = RGFormsModel::get_form_meta($config["form_id"]) : array();
        $settings = get_option("gf_paypalpro_settings");
        ?>
        <form method="post" action="">
            <input type="hidden" name="paypalpro_setting_id" value="<?php echo absint( $id ) ?>" />

            <div class="margin_vertical_10 <?php echo $is_validation_error ? "paypalpro_validation_error" : "" ?>">
                <?php
                if($is_validation_error){
                    ?>
                    <span><?php _e('There was an issue saving your feed. Please address the errors below and try again.'); ?></span>
                    <?php
                }
                ?>
            </div> <!-- / validation message -->

            <?php
            if($settings["ipn_configured"]=="on") {
            ?>
            <div class="margin_vertical_10">
                <label class="left_header" for="gf_paypalpro_type"><?php _e("Transaction Type", "gravityformspaypalpro"); ?> <?php gform_tooltip("paypalpro_transaction_type") ?></label>

                <select id="gf_paypalpro_type" name="gf_paypalpro_type" onchange="SelectType(jQuery(this).val());">
                    <option value=""><?php _e("Select a transaction type", "gravityformspaypalpro") ?></option>
                    <option value="product" <?php echo rgar($config['meta'], 'type') == "product" ? "selected='selected'" : "" ?>><?php _e("Products and Services", "gravityformspaypalpro") ?></option>
                    <option value="subscription" <?php echo rgar($config['meta'], 'type') == "subscription" ? "selected='selected'" : "" ?>><?php _e("Subscriptions", "gravityformspaypalpro") ?></option>
                </select>
            </div>
            <?php } else {$config["meta"]["type"]= "product" ?>

                  <input id="gf_paypalpro_type" type="hidden" name="gf_paypalpro_type" value="product">


            <?php } ?>
            <div id="paypalpro_form_container" valign="top" class="margin_vertical_10" <?php echo empty($config["meta"]["type"]) ? "style='display:none;'" : "" ?>>
                <label for="gf_paypalpro_form" class="left_header"><?php _e("Gravity Form", "gravityformspaypalpro"); ?> <?php gform_tooltip("paypalpro_gravity_form") ?></label>

                <select id="gf_paypalpro_form" name="gf_paypalpro_form" onchange="SelectForm(jQuery('#gf_paypalpro_type').val(), jQuery(this).val(), '<?php echo rgar($config, 'id') ?>');">
                    <option value=""><?php _e("Select a form", "gravityformspaypalpro"); ?> </option>
                    <?php

                    $active_form = rgar($config, 'form_id');
                    $available_forms = GFPayPalProData::get_available_forms($active_form);

                    foreach($available_forms as $current_form) {
                        $selected = absint($current_form->id) == rgar($config, 'form_id') ? 'selected="selected"' : '';
                        ?>

                            <option value="<?php echo absint($current_form->id) ?>" <?php echo $selected; ?>><?php echo esc_html($current_form->title) ?></option>

                        <?php
                    }
                    ?>
                </select>
                &nbsp;&nbsp;
                <img src="<?php echo GFPayPalPro::get_base_url() ?>/images/loading.gif" id="paypalpro_wait" style="display: none;"/>

                <div id="gf_paypalpro_invalid_product_form" class="gf_paypalpro_invalid_form"  style="display:none;">
                    <?php _e("The form selected does not have any Product fields. Please add a Product field to the form and try again.", "gravityformspaypalpro") ?>
                </div>
                <div id="gf_paypalpro_invalid_donation_form" class="gf_paypalpro_invalid_form" style="display:none;">
                    <?php _e("The form selected does not have any Donation fields. Please add a Donation field to the form and try again.", "gravityformspaypalpro") ?>
                </div>
            </div>
            <div id="paypalpro_field_group" valign="top" <?php echo empty($config["meta"]["type"]) || empty($config["form_id"]) ? "style='display:none;'" : "" ?>>

                <div id="paypalpro_field_container_subscription" class="paypalpro_field_container" valign="top" <?php echo rgars($config, "meta/type") != "subscription" ? "style='display:none;'" : ""?>>
                    <div class="margin_vertical_10">
                        <label class="left_header" for="gf_paypalpro_recurring_amount"><?php _e("Recurring Amount", "gravityformspaypalpro"); ?> <?php gform_tooltip("paypalpro_recurring_amount") ?></label>
                        <select id="gf_paypalpro_recurring_amount" name="gf_paypalpro_recurring_amount">
                            <?php echo self::get_product_options($form, rgar($config["meta"],"recurring_amount_field"),true) ?>
                        </select>
                    </div>

                    <div class="margin_vertical_10">
                        <label class="left_header" for="gf_paypalpro_billing_cycle_number"><?php _e("Billing Cycle", "gravityformspaypalpro"); ?> <?php gform_tooltip("paypalpro_billing_cycle") ?></label>
                        <select id="gf_paypalpro_billing_cycle_number" name="gf_paypalpro_billing_cycle_number">
                            <?php
                            for($i=1; $i<=100; $i++){
                            ?>
                                <option value="<?php echo $i ?>" <?php echo rgar($config["meta"],"billing_cycle_number") == $i ? "selected='selected'" : "" ?>><?php echo $i ?></option>
                            <?php
                            }
                            ?>
                        </select>&nbsp;
                        <select id="gf_paypalpro_billing_cycle_type" name="gf_paypalpro_billing_cycle_type" onchange="SetPeriodNumber('#gf_paypalpro_billing_cycle_number', jQuery(this).val());">
                            <option value="D" <?php echo rgars($config, "meta/billing_cycle_type") == "D" ? "selected='selected'" : "" ?>><?php _e("day(s)", "gravityformspaypalpro") ?></option>
                            <option value="W" <?php echo rgars($config, "meta/billing_cycle_type") == "W" ? "selected='selected'" : "" ?>><?php _e("week(s)", "gravityformspaypalpro") ?></option>
                            <option value="M" <?php echo rgars($config, "meta/billing_cycle_type") == "M" || strlen(rgars($config, "meta/billing_cycle_type")) == 0 ? "selected='selected'" : "" ?>><?php _e("month(s)", "gravityformspaypalpro") ?></option>
                            <option value="Y" <?php echo rgars($config, "meta/billing_cycle_type") == "Y" ? "selected='selected'" : "" ?>><?php _e("year", "gravityformspaypalpro") ?></option>
                        </select>
                    </div>

                    <div class="margin_vertical_10">
                        <label class="left_header" for="gf_paypalpro_recurring_times"><?php _e("Recurring Times", "gravityformspaypalpro"); ?> <?php gform_tooltip("paypalpro_recurring_times") ?></label>
                        <select id="gf_paypalpro_recurring_times" name="gf_paypalpro_recurring_times">
                            <option value=""><?php _e("Infinite", "gravityformspaypalpro") ?></option>
                            <?php
                            for($i=2; $i<=30; $i++){
                                $selected = ($i == rgar($config["meta"],"recurring_times")) ? 'selected="selected"' : '';
                                ?>
                                <option value="<?php echo $i ?>" <?php echo $selected; ?>><?php echo $i ?></option>
                                <?php
                            }
                            ?>
                        </select>
                    </div>

                    <div class="margin_vertical_10">
                        <label class="left_header" for="gf_paypalpro_setup_fee"><?php _e("Setup Fee", "gravityformspaypalpro"); ?> <?php gform_tooltip("paypalpro_setup_fee_enable") ?></label>
                        <input type="checkbox" onchange="if(this.checked) {jQuery('#gf_paypalpro_setup_fee_amount').val('Select a field');}" name="gf_paypalpro_setup_fee" id="gf_paypalpro_setup_fee" value="1" onclick="ToggleSetupFee();" <?php echo rgars($config, "meta/setup_fee_enabled") ? "checked='checked'" : ""?> />
                        <label class="inline" for="gf_paypalpro_setup_fee"><?php _e("Enable", "gravityformspaypalpro"); ?></label>
                        &nbsp;&nbsp;&nbsp;
                        <span id="paypalpro_setup_fee_container" <?php echo rgars($config, "meta/setup_fee_enabled") ? "" : "style='display:none;'" ?>>
                            <select id="gf_paypalpro_setup_fee_amount" name="gf_paypalpro_setup_fee_amount">
                                <?php echo self::get_product_options($form, rgar($config["meta"],"setup_fee_amount_field"),false) ?>
                            </select>
                        </span>
                    </div>

                    <div id='paypalpro_enable_trial_container' class="margin_vertical_10" <?php echo rgars($config, "meta/setup_fee_enabled") ? "style='display:none;'" : "" ?>>
                        <label class="left_header" for="gf_paypalpro_trial_period"><?php _e("Trial Period", "gravityformspaypalpro"); ?> <?php gform_tooltip("paypalpro_trial_period_enable") ?></label>
                        <input type="checkbox" name="gf_paypalpro_trial_period" id="gf_paypalpro_trial_period" value="1" onclick="ToggleTrial();" <?php echo rgars($config, "meta/trial_period_enabled") ? "checked='checked'" : ""?> />
                        <label class="inline" for="gf_paypalpro_trial_period"><?php _e("Enable", "gravityformspaypalpro"); ?></label>
                    </div>

                    <div id="paypalpro_trial_period_container" <?php echo rgars($config, "meta/trial_period_enabled") && !rgars($config, "meta/setup_fee_enabled") ? "" : "style='display:none;'" ?>>

                        <div class="margin_vertical_10">
                            <label class="left_header" for="gf_paypalpro_trial_type"><?php _e("Trial Amount", "gravityformspaypalpro"); ?> <?php gform_tooltip("paypalpro_trial_amount") ?></label>
                            <input type="radio" name="gf_paypalpro_trial_type" value="free" onclick="if(jQuery(this).is(':checked')) jQuery('#gf_paypalpro_trial_amount').val('Select a field');" <?php echo rgar($config["meta"],"trial_type") != "paid" ? "checked='checked'" : "" ?>/>
                            <label class="inline" for="gf_paypalpro_trial_type_free"><?php _e("Free", "gravityformspaypalpro"); ?></label>
                            &nbsp;&nbsp;&nbsp;
                            <input type="radio" name="gf_paypalpro_trial_type" value="paid" <?php echo rgar($config["meta"],"trial_type") == "paid" ? "checked='checked'" : "" ?>/>
                            <span id="paypalpro_trial_amount_values">
                                <select id="gf_paypalpro_trial_amount" name="gf_paypalpro_trial_amount" onchange="jQuery('[name=gf_paypalpro_trial_type]').filter('[value=paid]').prop('checked',true);">
                                    <?php echo self::get_product_options($form, rgar($config["meta"],"trial_amount_field"),false) ?>
                                </select>
                            </span>
                        </div>
                        <div class="margin_vertical_10">
                            <label class="left_header" for="gf_paypalpro_trial_period_number"><?php _e("Trial Billing Cycle", "gravityformspaypalpro"); ?> <?php gform_tooltip("paypalpro_trial_period") ?></label>
                            <select id="gf_paypalpro_trial_period_number" name="gf_paypalpro_trial_period_number">
                                <?php
                                for($i=1; $i<=100; $i++){
                                ?>
                                    <option value="<?php echo $i ?>" <?php echo rgars($config, "meta/trial_period_number") == $i ? "selected='selected'" : "" ?>><?php echo $i ?></option>
                                <?php
                                }
                                ?>
                            </select>&nbsp;
                            <select id="gf_paypalpro_trial_period_type" name="gf_paypalpro_trial_period_type" onchange="SetPeriodNumber('#gf_paypalpro_trial_period_number', jQuery(this).val());">
                                <option value="D" <?php echo rgars($config, "meta/trial_period_type") == "D" ? "selected='selected'" : "" ?>><?php _e("day(s)", "gravityformspaypalpro") ?></option>
                                <option value="W" <?php echo rgars($config, "meta/trial_period_type") == "W" ? "selected='selected'" : "" ?>><?php _e("week(s)", "gravityformspaypalpro") ?></option>
                                <option value="M" <?php echo rgars($config, "meta/trial_period_type") == "M" || empty($config["meta"]["trial_period_type"]) ? "selected='selected'" : "" ?>><?php _e("month(s)", "gravityformspaypalpro") ?></option>
                                <option value="Y" <?php echo rgars($config, "meta/trial_period_type") == "Y" ? "selected='selected'" : "" ?>><?php _e("year(s)", "gravityformspaypalpro") ?></option>
                            </select>
                        </div>
                        <!--<div class="margin_vertical_10">
                            <label class="left_header" for="gf_paypalpro_trial_recurring_times"><?php _e("Trial Recurring Times", "gravityformspaypalpro"); ?> <?php gform_tooltip("paypalpro_trial_recurring_times") ?></label>
                            <select id="gf_paypalpro_trial_recurring_times" name="gf_paypalpro_trial_recurring_times">
                                <?php
                                for($i=1; $i<=30; $i++){
                                    $selected = ($i == rgar($config["meta"],"trial_recurring_times")) ? 'selected="selected"' : '';
                                    ?>
                                    <option value="<?php echo $i ?>" <?php echo $selected; ?>><?php echo $i ?></option>
                                    <?php
                                }
                                ?>
                            </select>
                        </div>-->
                    </div>
                </div>

                <div class="margin_vertical_10">
                    <label class="left_header"><?php _e("Customer", "gravityformspaypalpro"); ?> <?php gform_tooltip("paypalpro_customer") ?></label>

                    <div id="paypalpro_customer_fields">
                        <?php
                            if(!empty($form))
                                echo self::get_customer_information($form, $config);
                        ?>
                    </div>
                </div>

                <?php
                    $display_post_fields = !empty($form) ? GFCommon::has_post_field($form["fields"]) : false;
                ?>

                <div class="margin_vertical_10"  >
                    <ul style="overflow:hidden;">
                        <li id="paypalpro_post_update_action" <?php echo $display_post_fields && $config["meta"]["type"] == "subscription" ? "" : "style='display:none;'" ?>>
                            <label class="left_header"><?php _e("Options", "gravityformspaypalpro"); ?> <?php gform_tooltip("paypalpro_options") ?></label>
                            <input type="checkbox" name="gf_paypalpro_update_post" id="gf_paypalpro_update_post" value="1" <?php echo rgar($config["meta"],"update_post_action") ? "checked='checked'" : ""?> onclick="var action = this.checked ? 'draft' : ''; jQuery('#gf_paypalpro_update_action').val(action);" />
                            <label class="inline" for="gf_paypalpro_update_post"><?php _e("Update Post when subscription is cancelled.", "gravityformspaypalpro"); ?> <?php gform_tooltip("paypalpro_update_post") ?></label>
                            <select id="gf_paypalpro_update_action" name="gf_paypalpro_update_action" onchange="var checked = jQuery(this).val() ? 'checked' : false; jQuery('#gf_paypalpro_update_post').attr('checked', checked);">
                                <option value=""></option>
                                <option value="draft" <?php echo rgar($config["meta"],"update_post_action") == "draft" ? "selected='selected'" : ""?>><?php _e("Mark Post as Draft", "gravityformspaypalpro") ?></option>
                                <option value="delete" <?php echo rgar($config["meta"],"update_post_action") == "delete" ? "selected='selected'" : ""?>><?php _e("Delete Post", "gravityformspaypalpro") ?></option>
                            </select>
                        </li>

                        <?php do_action("gform_paypalpro_action_fields", $config, $form) ?>
                    </ul>
                </div>

                <?php do_action("gform_paypalpro_add_option_group", $config, $form); ?>

                <div id="gf_paypalpro_conditional_section" valign="top" class="margin_vertical_10">
                    <label for="gf_paypalpro_conditional_optin" class="left_header"><?php _e("PayPal Pro Condition", "gravityformspaypalpro"); ?> <?php gform_tooltip("paypalpro_conditional") ?></label>

                    <div id="gf_paypalpro_conditional_option">
                        <table cellspacing="0" cellpadding="0">
                            <tr>
                                <td>
                                    <input type="checkbox" id="gf_paypalpro_conditional_enabled" name="gf_paypalpro_conditional_enabled" value="1" onclick="if(this.checked){jQuery('#gf_paypalpro_conditional_container').fadeIn('fast');} else{ jQuery('#gf_paypalpro_conditional_container').fadeOut('fast'); }" <?php echo rgar($config['meta'], 'paypalpro_conditional_enabled') ? "checked='checked'" : ""?>/>
                                    <label for="gf_paypalpro_conditional_enable"><?php _e("Enable", "gravityformspaypalpro"); ?></label>
                                </td>
                            </tr>
                            <tr>
                                <td>
                                    <div id="gf_paypalpro_conditional_container" <?php echo !rgar($config['meta'], 'paypalpro_conditional_enabled') ? "style='display:none'" : ""?>>

                                        <div id="gf_paypalpro_conditional_fields" style="display:none">
                                            <?php _e("Send to PayPal Pro if ", "gravityformspaypalpro") ?>

                                            <select id="gf_paypalpro_conditional_field_id" name="gf_paypalpro_conditional_field_id" class="optin_select" onchange='jQuery("#gf_paypalpro_conditional_value_container").html(GetFieldValues(jQuery(this).val(), "", 20));'></select>
                                            <select id="gf_paypalpro_conditional_operator" name="gf_paypalpro_conditional_operator">
                                                <option value="is" <?php echo rgar($config['meta'], 'paypalpro_conditional_operator') == "is" ? "selected='selected'" : "" ?>><?php _e("is", "gravityformspaypalpro") ?></option>
                                                <option value="isnot" <?php echo rgar($config['meta'], 'paypalpro_conditional_operator') == "isnot" ? "selected='selected'" : "" ?>><?php _e("is not", "gravityformspaypalpro") ?></option>
                                                <option value=">" <?php echo rgar($config['meta'], 'paypalpro_conditional_operator') == ">" ? "selected='selected'" : "" ?>><?php _e("greater than", "gravityformspaypalpro") ?></option>
                                                <option value="<" <?php echo rgar($config['meta'], 'paypalpro_conditional_operator') == "<" ? "selected='selected'" : "" ?>><?php _e("less than", "gravityformspaypalpro") ?></option>
                                                <option value="contains" <?php echo rgar($config['meta'], 'paypalpro_conditional_operator') == "contains" ? "selected='selected'" : "" ?>><?php _e("contains", "gravityformspaypalpro") ?></option>
                                                <option value="starts_with" <?php echo rgar($config['meta'], 'paypalpro_conditional_operator') == "starts_with" ? "selected='selected'" : "" ?>><?php _e("starts with", "gravityformspaypalpro") ?></option>
                                                <option value="ends_with" <?php echo rgar($config['meta'], 'paypalpro_conditional_operator') == "ends_with" ? "selected='selected'" : "" ?>><?php _e("ends with", "gravityformspaypalpro") ?></option>
                                            </select>
                                            <div id="gf_paypalpro_conditional_value_container" name="gf_paypalpro_conditional_value_container" style="display:inline;"></div>
                                        </div>
                                        <div id="gf_paypalpro_conditional_message" style="display:none">
                                            <?php _e("To create a registration condition, your form must have a field supported by conditional logic.", "gravityform") ?>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        </table>
                    </div>

                </div> <!-- / paypalpro conditional -->

                <div class="margin_vertical_10">
                        <label class="left_header" for="gf_paypalpro_api_settings"><?php _e("API Settings", "gravityformspaypalpro"); ?> <?php gform_tooltip("paypalpro_api_settings_enable") ?></label>
                        <input type="checkbox" name="gf_paypalpro_api_settings" id="gf_paypalpro_api_settings" value="1" onclick="if(jQuery(this).is(':checked')) jQuery('#paypalpro_api_settings_container').show('slow'); else jQuery('#paypalpro_api_settings_container').hide('slow');" <?php echo rgars($config, "meta/api_settings_enabled") ? "checked='checked'" : ""?> />
                        <label class="inline" for="gf_paypalpro_api_settings"><?php _e("Override Default Settings", "gravityformspaypalpro"); ?></label>
                </div>

                <div id="paypalpro_api_settings_container" <?php echo rgars($config, "meta/api_settings_enabled") ? "" : "style='display:none;'" ?>>

                    <div class="margin_vertical_10">
                        <label class="left_header" for="gf_paypalpro_api_mode"><?php _e("API", "gravityformspaypalpro"); ?> <?php gform_tooltip("paypalpro_api_mode") ?></label>
                        <input type="radio" name="gf_paypalpro_api_mode" value="production" <?php echo rgar($config["meta"],"api_mode") != "test" ? "checked='checked'" : "" ?>/>
                        <label class="inline" for="gf_paypalpro_api_mode_production"><?php _e("Production", "gravityformspaypalpro"); ?></label>
                        &nbsp;&nbsp;&nbsp;
                        <input type="radio" name="gf_paypalpro_api_mode" value="test" <?php echo rgar($config["meta"],"api_mode") == "test" ? "checked='checked'" : "" ?>/>
                        <label class="inline" for="gf_paypalpro_api_mode_test"><?php _e("Sandbox", "gravityformspaypalpro"); ?></label>
                    </div>

                    <div class="margin_vertical_10">
                        <label class="left_header" for="gf_paypalpro_api_username"><?php _e("API Username", "gravityformspaypalpro"); ?> <?php gform_tooltip("paypalpro_api_username") ?></label>
                        <input class="size-1" id="gf_paypalpro_api_username" name="gf_paypalpro_api_username" value="<?php echo rgar($config["meta"],"api_username") ?>" />
                        <img src="<?php echo self::get_base_url() ?>/images/<?php echo rgars($config, "meta/api_valid") ? "tick.png" : "stop.png" ?>" border="0" alt="<?php echo $config["meta"]["api_message"]  ?>" title="<?php echo $config["meta"]["api_message"] ?>" style="display:<?php echo empty($config["meta"]["api_message"]) ? 'none;' : 'inline;' ?>" />
                    </div>

                    <div class="margin_vertical_10">
                        <label class="left_header" for="gf_paypalpro_api_password"><?php _e("API Password", "gravityformspaypalpro"); ?> <?php gform_tooltip("paypalpro_api_password") ?></label>
                        <input class="size-1" id="gf_paypalpro_api_password" name="gf_paypalpro_api_password" value="<?php echo rgar($config["meta"],"api_password") ?>" />
                        <img src="<?php echo self::get_base_url() ?>/images/<?php echo rgars($config, "meta/api_valid") ? "tick.png" : "stop.png" ?>" border="0" alt="<?php echo $config["meta"]["api_message"] ?>" title="<?php echo $config["meta"]["api_message"] ?>" style="display:<?php echo empty($config["meta"]["api_message"]) ? 'none;' : 'inline;' ?>" />
                    </div>

                    <div class="margin_vertical_10">
                        <label class="left_header" for="gf_paypalpro_api_signature"><?php _e("API Signature", "gravityformspaypalpro"); ?> <?php gform_tooltip("paypalpro_api_signature") ?></label>
                        <input class="size-1" id="gf_paypalpro_api_signature" name="gf_paypalpro_api_signature" value="<?php echo rgar($config["meta"],"api_signature") ?>" />
                        <img src="<?php echo self::get_base_url() ?>/images/<?php echo rgars($config, "meta/api_valid") ? "tick.png" : "stop.png" ?>" border="0" alt="<?php echo $config["meta"]["api_message"] ?>" title="<?php echo $config["meta"]["api_message"] ?>" style="display:<?php echo empty($config["meta"]["api_message"]) ? 'none;' : 'inline;' ?>" />
                    </div>

                </div>

                <div id="paypalpro_submit_container" class="margin_vertical_30">
                    <input type="submit" name="gf_paypalpro_submit" value="<?php echo empty($id) ? __("  Save  ", "gravityformspaypalpro") : __("Update", "gravityformspaypalpro"); ?>" class="button-primary"/>
                    <input type="button" value="<?php _e("Cancel", "gravityformspaypalpro"); ?>" class="button" onclick="javascript:document.location='admin.php?page=gf_paypalpro'" />
                </div>
            </div>
        </form>
        </div>

        <script type="text/javascript">
            jQuery(document).ready(function(){
                SetPeriodNumber('#gf_paypalpro_billing_cycle_number', jQuery("#gf_paypalpro_billing_cycle_type").val());
                SetPeriodNumber('#gf_paypalpro_trial_period_number', jQuery("#gf_paypalpro_trial_period_type").val());
            });

            function SelectType(type){
                jQuery("#paypalpro_field_group").slideUp();

                jQuery("#paypalpro_field_group input[type=\"text\"], #paypalpro_field_group select").val("");
                jQuery("#gf_paypalpro_trial_period_type, #gf_paypalpro_billing_cycle_type").val("M");

                jQuery("#paypalpro_field_group input:checked").attr("checked", false);

                if(type){
                    jQuery("#paypalpro_form_container").slideDown();
                    jQuery("#gf_paypalpro_form").val("");
                }
                else{
                    jQuery("#paypalpro_form_container").slideUp();
                }
            }

            function SelectForm(type, formId, settingId){
                if(!formId){
                    jQuery("#paypalpro_field_group").slideUp();
                    return;
                }

                jQuery("#paypalpro_wait").show();
                jQuery("#paypalpro_field_group").slideUp();

                var mysack = new sack(ajaxurl);
                mysack.execute = 1;
                mysack.method = 'POST';
                mysack.setVar( "action", "gf_select_paypalpro_form" );
                mysack.setVar( "gf_select_paypalpro_form", "<?php echo wp_create_nonce("gf_select_paypalpro_form") ?>" );
                mysack.setVar( "type", type);
                mysack.setVar( "form_id", formId);
                mysack.setVar( "setting_id", settingId);
                mysack.encVar( "cookie", document.cookie, false );
                mysack.onError = function() {jQuery("#paypalpro_wait").hide(); alert('<?php _e("Ajax error while selecting a form", "gravityformspaypalpro") ?>' )};
                mysack.runAJAX();

                return true;
            }

            function EndSelectForm(form_meta, customer_fields, recurring_amount_options, product_field_options){
                //setting global form object
                form = form_meta;

                var type = jQuery("#gf_paypalpro_type").val();

                jQuery(".gf_paypalpro_invalid_form").hide();
                if( (type == "product" || type =="subscription") && GetFieldsByType(["product"]).length == 0){
                    jQuery("#gf_paypalpro_invalid_product_form").show();
                    jQuery("#paypalpro_wait").hide();
                    return;
                }
                else if(type == "donation" && GetFieldsByType(["product", "donation"]).length == 0){
                    jQuery("#gf_paypalpro_invalid_donation_form").show();
                    jQuery("#paypalpro_wait").hide();
                    return;
                }

                jQuery(".paypalpro_field_container").hide();
                jQuery("#paypalpro_customer_fields").html(customer_fields);
                jQuery("#gf_paypalpro_recurring_amount").html(recurring_amount_options);
                jQuery("#gf_paypalpro_trial_amount").html(product_field_options);
                jQuery("#gf_paypalpro_setup_fee_amount").html(product_field_options);

                //displaying delayed post creation setting if current form has a post field
                var post_fields = GetFieldsByType(["post_title", "post_content", "post_excerpt", "post_category", "post_custom_field", "post_image", "post_tag"]);
                if(post_fields.length > 0){
                    jQuery("#paypalpro_post_action").show();
                }
                else{
                    //jQuery("#gf_paypalpro_delay_post").attr("checked", false);
                    jQuery("#paypalpro_post_action").hide();
                }

                if(type == "subscription" && post_fields.length > 0){
                    jQuery("#paypalpro_post_update_action").show();
                }
                else{
                    jQuery("#gf_paypalpro_update_post").attr("checked", false);
                    jQuery("#paypalpro_post_update_action").hide();
                }

                SetPeriodNumber('#gf_paypalpro_billing_cycle_number', jQuery("#gf_paypalpro_billing_cycle_type").val());
                SetPeriodNumber('#gf_paypalpro_trial_period_number', jQuery("#gf_paypalpro_trial_period_type").val());

                //Calling callback functions
                jQuery(document).trigger('paypalproFormSelected', [form]);

                jQuery("#gf_paypalpro_conditional_enabled").attr('checked', false);
                SetPayPalProCondition("","");

                jQuery("#paypalpro_field_container_" + type).show();
                jQuery("#paypalpro_field_group").slideDown();
                jQuery("#paypalpro_wait").hide();
            }

            function SetPeriodNumber(element, type){
                var prev = jQuery(element).val();

                var min = 1;
                var max = 0;
                switch(type){
                    case "D" :
                        max = 100;
                    break;
                    case "W" :
                        max = 52;
                    break;
                    case "M" :
                        max = 12;
                    break;
                    case "Y" :
                        max = 1;
                    break;
                }
                var str="";
                for(var i=min; i<=max; i++){
                    var selected = prev == i ? "selected='selected'" : "";
                    str += "<option value='" + i + "' " + selected + ">" + i + "</option>";
                }
                jQuery(element).html(str);
            }

            function GetFieldsByType(types){
                var fields = new Array();
                for(var i=0; i<form["fields"].length; i++){
                    if(IndexOf(types, form["fields"][i]["type"]) >= 0)
                        fields.push(form["fields"][i]);
                }
                return fields;
            }

            function IndexOf(ary, item){
                for(var i=0; i<ary.length; i++)
                    if(ary[i] == item)
                        return i;

                return -1;
            }

        </script>

        <script type="text/javascript">

            // Paypal Conditional Functions

            <?php
            if(!empty($config["form_id"])){
                ?>

                // initilize form object
                form = <?php echo GFCommon::json_encode($form)?> ;

                // initializing registration condition drop downs
                jQuery(document).ready(function(){
                    var selectedField = "<?php echo str_replace('"', '\"', $config["meta"]["paypalpro_conditional_field_id"])?>";
                    var selectedValue = "<?php echo str_replace('"', '\"', $config["meta"]["paypalpro_conditional_value"])?>";
                    SetPayPalProCondition(selectedField, selectedValue);
                });

                <?php
            }
            ?>

            function SetPayPalProCondition(selectedField, selectedValue){

                // load form fields
                jQuery("#gf_paypalpro_conditional_field_id").html(GetSelectableFields(selectedField, 20));
                var optinConditionField = jQuery("#gf_paypalpro_conditional_field_id").val();
                var checked = jQuery("#gf_paypalpro_conditional_enabled").attr('checked');

                if(optinConditionField){
                    jQuery("#gf_paypalpro_conditional_message").hide();
                    jQuery("#gf_paypalpro_conditional_fields").show();
                    jQuery("#gf_paypalpro_conditional_value_container").html(GetFieldValues(optinConditionField, selectedValue, 20));
                    jQuery("#gf_paypalpro_conditional_value").val(selectedValue);
                }
                else{
                    jQuery("#gf_paypalpro_conditional_message").show();
                    jQuery("#gf_paypalpro_conditional_fields").hide();
                }

                if(!checked) jQuery("#gf_paypalpro_conditional_container").hide();

            }

            function GetFieldValues(fieldId, selectedValue, labelMaxCharacters){
                if(!fieldId)
                    return "";

                var str = "";
                var field = GetFieldById(fieldId);
                if(!field)
                    return "";

                var isAnySelected = false;

                if(field["type"] == "post_category" && field["displayAllCategories"]){
                    str += '<?php $dd = wp_dropdown_categories(array("class"=>"optin_select", "orderby"=> "name", "id"=> "gf_paypalpro_conditional_value", "name"=> "gf_paypalpro_conditional_value", "hierarchical"=>true, "hide_empty"=>0, "echo"=>false)); echo str_replace("\n","", str_replace("'","\\'",$dd)); ?>';
                }
                else if(field.choices){
                    str += '<select id="gf_paypalpro_conditional_value" name="gf_paypalpro_conditional_value" class="optin_select">'

                    for(var i=0; i<field.choices.length; i++){
                        var fieldValue = field.choices[i].value ? field.choices[i].value : field.choices[i].text;
                        var isSelected = fieldValue == selectedValue;
                        var selected = isSelected ? "selected='selected'" : "";
                        if(isSelected)
                            isAnySelected = true;

                        str += "<option value='" + fieldValue.replace(/'/g, "&#039;") + "' " + selected + ">" + TruncateMiddle(field.choices[i].text, labelMaxCharacters) + "</option>";
                    }

                    if(!isAnySelected && selectedValue){
                        str += "<option value='" + selectedValue.replace(/'/g, "&#039;") + "' selected='selected'>" + TruncateMiddle(selectedValue, labelMaxCharacters) + "</option>";
                    }
                    str += "</select>";
                }
                else
                {
                    selectedValue = selectedValue ? selectedValue.replace(/'/g, "&#039;") : "";
                    //create a text field for fields that don't have choices (i.e text, textarea, number, email, etc...)
                    str += "<input type='text' placeholder='<?php _e("Enter value", "gravityforms"); ?>' id='gf_paypalpro_conditional_value' name='gf_paypalpro_conditional_value' value='" + selectedValue.replace(/'/g, "&#039;") + "'>";
                }

                return str;
            }

            function GetFieldById(fieldId){
                for(var i=0; i<form.fields.length; i++){
                    if(form.fields[i].id == fieldId)
                        return form.fields[i];
                }
                return null;
            }

            function TruncateMiddle(text, maxCharacters){
                if(!text)
                    return "";

                if(text.length <= maxCharacters)
                    return text;
                var middle = parseInt(maxCharacters / 2);
                return text.substr(0, middle) + "..." + text.substr(text.length - middle, middle);
            }

            function GetSelectableFields(selectedFieldId, labelMaxCharacters){
                var str = "";
                var inputType;
                for(var i=0; i<form.fields.length; i++){
                    fieldLabel = form.fields[i].adminLabel ? form.fields[i].adminLabel : form.fields[i].label;
                    inputType = form.fields[i].inputType ? form.fields[i].inputType : form.fields[i].type;
                    if (IsConditionalLogicField(form.fields[i])) {
                        var selected = form.fields[i].id == selectedFieldId ? "selected='selected'" : "";
                        str += "<option value='" + form.fields[i].id + "' " + selected + ">" + TruncateMiddle(fieldLabel, labelMaxCharacters) + "</option>";
                    }
                }
                return str;
            }

            function IsConditionalLogicField(field){
                inputType = field.inputType ? field.inputType : field.type;
                var supported_fields = ["checkbox", "radio", "select", "text", "website", "textarea", "email", "hidden", "number", "phone", "multiselect", "post_title",
                                        "post_tags", "post_custom_field", "post_content", "post_excerpt"];

                var index = jQuery.inArray(inputType, supported_fields);

                return index >= 0;
            }

        </script>

        <?php

    }

    public static function select_paypalpro_form(){

        check_ajax_referer("gf_select_paypalpro_form", "gf_select_paypalpro_form");

        $type = $_POST["type"];
        $form_id =  intval($_POST["form_id"]);
        $setting_id =  intval($_POST["setting_id"]);

        //fields meta
        $form = RGFormsModel::get_form_meta($form_id);

        $customer_fields = self::get_customer_information($form);
        $recurring_amount_fields = self::get_product_options($form, "",true);
        $product_fields = self::get_product_options($form, "",false);

        die("EndSelectForm(" . GFCommon::json_encode($form) . ", '" . str_replace("'", "\'", $customer_fields) . "', '" . str_replace("'", "\'", $recurring_amount_fields) . "', '" . str_replace("'", "\'", $product_fields) . "');");
    }

    public static function add_permissions(){
        global $wp_roles;
        $wp_roles->add_cap("administrator", "gravityforms_paypalpro");
        $wp_roles->add_cap("administrator", "gravityforms_paypalpro_uninstall");
    }

    //Target of Member plugin filter. Provides the plugin with Gravity Forms lists of capabilities
    public static function members_get_capabilities( $caps ) {
        return array_merge($caps, array("gravityforms_paypalpro", "gravityforms_paypalpro_uninstall"));
    }

    public static function has_paypalpro_condition($form, $config) {

        $config = $config["meta"];

        $operator = isset($config["paypalpro_conditional_operator"]) ? $config["paypalpro_conditional_operator"] : "";
        $field = RGFormsModel::get_field($form, $config["paypalpro_conditional_field_id"]);

        if(empty($field) || !$config["paypalpro_conditional_enabled"])
            return true;

        // if conditional is enabled, but the field is hidden, ignore conditional
        $is_visible = !RGFormsModel::is_field_hidden($form, $field, array());

        $field_value = RGFormsModel::get_field_value($field, array());

        $is_value_match = RGFormsModel::is_value_match($field_value, $config["paypalpro_conditional_value"], $operator);
        $go_to_paypalpro = $is_value_match && $is_visible;

        return  $go_to_paypalpro;
    }

    public static function get_config_by_entry($entry_id){
        //loading data lib
        require_once(self::get_base_path() . "/data.php");

        $feed_id = gform_get_meta($entry_id, "paypalpro_feed_id");
        $config = GFPayPalProData::get_feed($feed_id);
        return $config;
    }

    public static function get_config($form){
        global $__config;

        if($__config)
            return $__config;

        $__config = false;

        require_once(self::get_base_path() . "/data.php");

        //Getting settings associated with this transaction
        $configs = GFPayPalProData::get_feed_by_form($form["id"]);
        if(!$configs)
            return false;

        foreach($configs as $config){
            if(self::has_paypalpro_condition($form, $config)){
                $__config = $config;
                return $__config;
            }
        }

        return false;
    }

    public static function get_creditcard_field($form){
        $fields = GFCommon::get_fields_by_type($form, array("creditcard"));
        return empty($fields) ? false : $fields[0];
    }

    private static function is_ready_for_capture($validation_result){
        $form            = $validation_result['form'];
        $is_last_page    = GFFormDisplay::is_last_page( $form );
        $failed_honeypot = false;

        if ( $is_last_page && rgar( $form, 'enableHoneypot' ) ) {
            $honeypot_id     = GFFormDisplay::get_max_field_id( $form ) + 1;
            $failed_honeypot = ! rgempty( "input_{$honeypot_id}" );
        }

        $is_heartbeat = rgpost( 'action' ) == 'heartbeat'; // Validation called by partial entries feature via the heartbeat API.

        if ( ! $validation_result['is_valid'] || ! $is_last_page || $failed_honeypot || $is_heartbeat ) {
            return false;
        }

        //getting config that matches condition (if conditions are enabled)
        $config = self::get_config($validation_result["form"]);
        if(!$config)
            return false;

        //making sure credit card field is visible
        $creditcard_field = self::get_creditcard_field($validation_result["form"]);
        if(RGFormsModel::is_field_hidden($validation_result["form"], $creditcard_field, array()))
            return false;

        return $config;
    }

    private static function is_last_page($form){
        $current_page = GFFormDisplay::get_source_page($form["id"]);
        $target_page = GFFormDisplay::get_target_page($form, $current_page, rgpost("gform_field_values"));
        return $target_page == 0;
    }

    private static function has_visible_products($form){

        foreach($form["fields"] as $field){
            if($field["type"] == "product" && !RGFormsModel::is_field_hidden($form, $field, ""))
                return true;
        }
        return false;
    }

    public static function get_recurring_description($config, $billing_data){

        $description = "";

        $setup_fee_amount = self::get_setup_fee($config, $billing_data);
        if($setup_fee_amount){
            //adding setup fee if it is enabled
            $description = GFCommon::to_money($setup_fee_amount) . " " . __("initial payment, then", "gravityformspaypalpro") . " ";
        }
        else{
            //adding trial description if trial is enabled
            $trial_data = self::get_trial_data($config, $billing_data);
            if($trial_data){
                $trial_cost = $trial_data["amount"] == 0 ? "FREE" : GFCommon::to_money($trial_data["amount"]);
                $description = self::get_time_period($trial_data["frequency"], $trial_data["period"], "number_unit") . " " . $trial_cost . " " . __("trial, then", "gravityformspaypalpro") . " ";
            }
        }

        //adding price
        $description .= GFCommon::to_money($billing_data["amount"]);

        //adding recurring period
        if($config["meta"]["type"] != "product"){
            $description .= self::get_time_period($config["meta"]["billing_cycle_number"], $config["meta"]["billing_cycle_type"], "compact_with_separator");
        }

        return $description;
    }

    public static function get_time_period($number, $unit_code, $format="unit_number"){
        if(empty($unit_code))
            return "";

        switch(strtoupper(substr($unit_code, 0, 1))){
            case "D" :
                $unit = __("Day", "gravityformspaypalpro");
                $unit_plural = __("Days", "gravityformspaypalpro");
            break;

            case "W" :
                $unit = __("Week", "gravityformspaypalpro");
                $unit_plural = __("Weeks", "gravityformspaypalpro");
            break;

            case "M" :
                $unit = __("Month", "gravityformspaypalpro");
                $unit_plural = __("Months", "gravityformspaypalpro");
            break;

            case "Y" :
                $unit = __("Year", "gravityformspaypalpro");
                $unit_plural = __("Years", "gravityformspaypalpro");
            break;
        }

        switch($format){
            case "unit_number" :
                return $unit . " - " . $number;
            break;

            case "compact_with_separator" :
                if($number == 1)
                    $period = "/" . strtolower($unit);
                else
                    $period = " " . sprintf(__("every %s", "gravityformspaypalpro"), $number . " " . strtolower($unit_plural) );

                return $period;
            break;

            case "number_unit" :
            default :
                $unit = $number == 1 ? $unit : $unit_plural;
                return strtolower($number . " " . $unit);
            break;
        }

    }

    public static function start_express_checkout($confirmation, $form, $entry, $ajax){

        $config = self::get_config($form);
        if(!$config)
            return $confirmation;

        $product_billing_data = self::get_product_billing_data($form, $entry, $config);
        $amount = $product_billing_data["amount"];
        $products = $product_billing_data["products"];
        $billing = $product_billing_data["billing"];

        //TODO: add to settings
        $allow_note = "0"; //rgar($gateway_settings, "allownote") ? "1": "0";

        //1- send to paypal and get response
        $fields =   "METHOD=SetExpressCheckout&";

        if($config["meta"]["type"] != "product"){
            $fields .=  "L_BILLINGTYPE0=RecurringPayments&".
                        "PAYMENTREQUEST_0_AMT={$amount}&".
                        "L_BILLINGAGREEMENTDESCRIPTION0=" . urlencode(self::get_recurring_description($config, $product_billing_data)) . "&";

        }

        else{
            $fields .=
                        "PAYMENTREQUEST_0_AMT={$amount}&".
                        "PAYMENTREQUEST_0_CURRENCYCODE=" . GFCommon::get_currency() . "&".
                        "REQCONFIRMSHIPPING=0&".
                        "ALLOWNOTE={$allow_note}&".
                        "NOSHIPPING=1&";

                        for($i=0; $i < $product_billing_data["line_items"]; $i++){
                            $fields .=  "L_PAYMENTREQUEST_0_NAME{$i}=" . urlencode($billing["L_NAME{$i}"]) . "&".
                                        "L_PAYMENTREQUEST_0_AMT{$i}=" . $billing["L_AMT{$i}"] . "&".
                                        "L_PAYMENTREQUEST_0_QTY{$i}=" . $billing["L_QTY{$i}"] . "&";
                        }

        }

        $cancel_url = rgempty("cancel_url", $config) ? RGFormsModel::get_current_page_url() : rgar($config, "cancel_url");
        $fields .=  "CANCELURL=" . urlencode($cancel_url) . "&".
                    "RETURNURL=" . urlencode(RGFormsModel::get_current_page_url()) . "&".
                    "PAGESTYLE=". urlencode(rgar($config, "page_style")) ."&".
                    "LOCALECODE=". urlencode(rgar($config, "locale")) ."&".
                    "EMAIL=" . urlencode(rgar($data,"user_email"));

        $response = array();
        $success = self::send_request($config, $fields, $response, $form, $entry);

        if(!$success){
            //GCCommon::log_error("paypalpro", "Error on SetExpressCheckout call \n\nFields:\n {$fields} \n\nResponse:\n " . print_r($response, true));

            return __("There was an error while contacting PayPal. Your payment could not be processed. Please try again later", "gravityformspaypalpro");
        }
        else{

            //Getting Url (Production or Sandbox)
            $url = rgar($config, "mode") == "production" ? self::$production_express_checkout_url : self::$sandbox_express_checkout_url;
            $url .= "?cmd=_express-checkout&token={$response["TOKEN"]}";

            gform_update_meta($entry["id"], "paypalpro_express_checkout_token", $response["TOKEN"]);

            //Redirecting to paypal
            return array("redirect" => $url);
        }
    }

    public static function maybe_confirm_express_checkout($form_string){
        $token = rgget("token");


        if(empty($token) || rgempty("PayerID", $_GET)){
            return $form_string;
        }
        else{
            $entries = RGFormsModel::get_leads_by_meta("paypalpro_express_checkout_token", $token);

            $is_error = false;
            if(empty($entries) || count($entries) == 0)
                $is_error = true;

            $entry = $entries[0]; //getting first entry (there should always be just one)
            $form = RGFormsModel::get_form_meta($entry["form_id"]);
            $config = self::get_config_by_entry($entry["id"]);

            if(!$config)
                $is_error = true;

            if($is_error)
                return __("Oops! There was an error while processing your order and the transaction could not be completed. Please try again later", "gravityformspaypalpro");

            //getting billing data
            $billing_data = self::get_product_billing_data($form, $entry, $config);

            if(isset($_POST["paypalpro_confim_payment"])){
                return self::confirm_express_checkout($form, $entry, $config, $billing_data);
            }
            else{
                return self::confirmation_page($config, $billing_data, $entry, $form);
            }
        }
    }

    public static function load_template($filename, $data = array()) {

        $filename = apply_filters('gform_paypalpro_load_template', $filename, $data);
        $template_path = trailingslashit(apply_filters('gform_paypalpro_template_path', self::get_base_path() . "/templates", $filename, $data));

        ob_start();
        extract($data);

        $custom_path = STYLESHEETPATH . '/gravityformspaypalpro/';
        if(file_exists($custom_path . "/{$filename}")) {
            require_once($custom_path . "/{$filename}");
        }
        else if(file_exists($template_path . $filename)){
            require_once($template_path . $filename);
        }
        else{
            ob_get_clean();
            return "";
        }

        $ob = ob_get_clean();
        return $ob;
    }

    public static function confirmation_page($config, $billing_data, $entry, $form){

        //compiling products that will be used for the recurring calculation
        $recurring_products = array();
        $recurring_amount = 0;
        foreach($billing_data["products"]["products"] as $product_id => $product){
            if(self::include_in_total($product_id, $config)){
                $recurring_products[] = $product;
                $recurring_amount += self::get_product_price($product);
            }
        }
        if($config["meta"]["type"] == "product")
            $recurring_amount = 0;

        self::get_interval_unit($config["meta"]["billing_cycle_type"]);
        $recurring_label = self::get_recurring_label($config);
        $setup_fee = self::get_setup_fee($config, $billing_data);

        $trial_data = self::get_trial_data($config, $billing_data);
        $trial_label = sprintf(__("%s %s trial", "gravityformspaypalpro"), $trial_data["frequency"], $trial_data["period"]);

        $total_label = $config["meta"]["type"] == "product" ? __("Total", "gravityformspaypalpro") : __("Today's Payment", "gravityformspaypalpro");
        $todays_payment = $trial_data ? $trial_data["amount"] : $setup_fee + $recurring_amount;
        $total = $config["meta"]["type"] == "product" ? GFCommon::get_order_total($form, $entry) : $todays_payment;
        $output = self::load_template("payment_confirmation.php",
                                        array(  "setup_fee" => self::get_setup_fee($config, $billing_data),
                                                "trial_data" => self::get_trial_data($config, $billing_data),
                                                "trial_label" => $trial_label,
                                                "recurring_products" => $recurring_products,
                                                "recurring_amount" => $recurring_amount,
                                                "recurring_label" => $recurring_label,
                                                "total_label" => $total_label,
                                                "total_amount" => $total,
                                                "entry" => $entry,
                                                "form" => $form,
                                                "products" => $billing_data["products"] ));

        return $output;

    }

    public static function get_recurring_label($config){
        if($config["meta"]["billing_cycle_number"] == 1){
            $label = "";
            //i.e. Monthly payment
            switch($config["meta"]["billing_cycle_type"]){
                case "D" :
                    $label = __("Daily Payment", "gravityformspaypalpro");
                    break;
                case "W" :
                    $label = __("Weekly Payment", "gravityformspaypalpro");
                    break;
                case "M" :
                    $label = __("Monthly Payment", "gravityformspaypalpro");
                    break;
                case "Y" :
                    $label = __("Yearly Payment", "gravityformspaypalpro");
                    break;
            }
            return $label;
        }
        else{
            //i.e. Recurring payment (every 2 months)
            return sprintf(__("Recurring payment (%s)", "gravityformspaypalpro"), self::get_time_period($config["meta"]["billing_cycle_number"], $config["meta"]["billing_cycle_type"], "compact_with_separator"));
        }
    }

    public static function get_setup_fee($config, $billing_data){
        if(!$config["meta"]["setup_fee_enabled"])
            return 0;

        $setup_fee_product = rgar($billing_data["products"]["products"], $config["meta"]["setup_fee_amount_field"]);
        if(!empty($setup_fee_product)){
            $setup_fee_amount = self::get_product_price($setup_fee_product);
            return $setup_fee_amount;
        }
        return 0;
    }

    public static function get_trial_data($config, $billing_data){
        if(!$config["meta"]["trial_period_enabled"])
            return false;

        $trial_amount = 0;
        if($config["meta"]["trial_type"] == "paid")
        {
            $trial_product = rgar($billing_data["products"]["products"], $config["meta"]["trial_amount_field"]);
            $trial_amount = empty($trial_product) ? 0 : self::get_product_price($trial_product);
        }

        $trial_data = array("amount" => $trial_amount,
                            "period" => self::get_interval_unit($config["meta"]["trial_period_type"]),
                            "frequency" => $config["meta"]["trial_period_number"],
                            "cycles" => $config["meta"]["trial_recurring_times"]);

        return $trial_data;
    }


    public static function confirm_express_checkout($form, $entry, $config, $billing_data){
        $token = rgget("token");

        //finalize PayPal transaction
        if($config["meta"]["type"] != "product"){

            $fields =   "METHOD=CreateRecurringPaymentsProfile&" .
                        "TOKEN={$token}&".
                        "PROFILESTARTDATE=" . urlencode(gmdate(DATE_ATOM)) . "&".
                        "DESC=" . urlencode(self::get_recurring_description($config, $billing_data)) . "&".
                        "MAXFAILEDPAYMENTS=0&".
                        "BILLINGPERIOD=" . self::get_interval_unit($config["meta"]["billing_cycle_type"]) . "&".
                        "BILLINGFREQUENCY=" . $config["meta"]["billing_cycle_number"] . "&".
                        "AMT=" . $billing_data["amount"] . "&".
                        "CURRENCYCODE=" . GFCommon::get_currency() . "&".
                        "TOTALBILLINGCYCLES=" . $config["meta"]["recurring_times"] . "&";

            $trial_data = self::get_trial_data($config, $billing_data);
            $trial_amount = 0;
            if($trial_data){
                $fields .=  "TRIALBILLINGPERIOD=" . $trial_data["period"] . "&".
                            "TRIALBILLINGFREQUENCY=" . $trial_data["frequency"] . "&".
                            "TRIALTOTALBILLINGCYCLES=" . $trial_data["cycles"] . "&";

                if($trial_data["amount"] > 0)
                    $fields .= "TRIALAMT=" . $trial_data["amount"] . "&";

            }

            //setup fee
            $setup_fee_amount = self::get_setup_fee($config, $billing_data);
            if($setup_fee_amount)
                $fields .= "INITAMT=" . $setup_fee_amount;

            $success = self::send_request($config, $fields, $response, $form, $entry);
            if(!$success || !in_array($response["PROFILESTATUS"], array("PendingProfile", "ActiveProfile"))){
                //3a- if failure, display message and abort
                self::log_error("Error on CreateRecurringPaymentsProfile \n\nFields:\n{$fields} \n\nResponse:\n " . print_r($response, true));
                return __("There was an error while confirming your payment. Your payment could not be processed. Please try again later.", "gravityformspaypalpro");
            }
            else{
                //Everything OK. confirm subscription
                $subscriber_id = rgar($response, "PROFILEID");
                $is_pending = $response["PROFILESTATUS"] == "PendingProfile";
                $amount = $billing_data["amount"];

                //marking entry as Active
                self::update_entry($entry, $form, $subscriber_id, true, $amount, $is_pending);

                //inserting initial signup transaction
                GFPayPalProData::insert_transaction($entry["id"], $config["id"], "signup", $subscriber_id, "", "", 0);

                //fulfilling order if profile was created successfully
                if(!$is_pending){
                    self::fulfill_order($entry, $subscriber_id, $setup_fee_amount, $amount);
                }

                $confirmation = GFFormDisplay::handle_confirmation($form, $entry);
                return $confirmation;
            }
        }
        else{
            //Confirm payment
            $fields =   "METHOD=DoExpressCheckoutPayment&" .
                        "PAYERID=" . rgget("PayerID") . "&".
                        "PAYMENTREQUEST_0_AMT=" . $billing_data["amount"] . "&".
                        "PAYMENTREQUEST_0_NOTIFYURL=" . urlencode(get_bloginfo("url") . "/?page=gf_paypalpro_ipn") . "&" .
                        "TOKEN={$token}&";

            $success = self::send_request($config, $fields, $response, $form, $entry);

            if(!$success || !in_array($response["PAYMENTINFO_0_PAYMENTSTATUS"], array("Pending", "Completed"))){
                //GCCommon::log_error("paypalpro", "Error on DoExpressCheckoutPayment \n\nFields:\n{$fields} \n\nResponse:\n " . print_r($response, true));
                return __("There was an error while confirming your payment. Your payment could not be processed. Please try again later.", "gravityformspaypalpro");
            }
            else{
                $transaction_id = rgar($response, "PAYMENTINFO_0_TRANSACTIONID");
                $is_pending = $response["PAYMENTINFO_0_PAYMENTSTATUS"] == "Pending";
                $amount = $response["PAYMENTINFO_0_AMT"];
                self::confirm_payment($entry, $form, "", $transaction_id, false, $amount, 0, $is_pending);

                $confirmation = GFFormDisplay::handle_confirmation($form, $entry);
                return $confirmation;
            }
        }
    }

    public static function fulfill_order($entry, $transaction_id, $initial_payment_amount, $subscription_amount){
        $form = RGFormsModel::get_form_meta($entry["form_id"]);
        $config = self::get_config_by_entry($entry["id"]);

        if($config){
            self::log_debug("Creating post.");
            RGFormsModel::create_post($form, $entry);
            self::log_debug("Post created.");

            self::log_debug("Sending admin notification.");
            GFCommon::send_admin_notification($form, $entry);

            self::log_debug("Sending user notification.");
            GFCommon::send_user_notification($form, $entry);
        }

        do_action("gform_paypalpro_fulfillment", $entry, $config, $transaction_id, $initial_payment_amount, $subscription_amount);
    }

    public static function send_request($config, $fields, &$response=array(), $form, $entry){

        $has_feed_api = $config["meta"]["api_settings_enabled"];
        $mode = $has_feed_api ? $config["meta"]["api_mode"] : self::get_mode();

        $url = $mode == "production" ? self::$production_url : self::$sandbox_url;

        $login = array( "USER"=> $has_feed_api ? $config["meta"]["api_username"] : self::get_username(),
                        "PWD"=> $has_feed_api ? $config["meta"]["api_password"] : self::get_password(),
                        "SIGNATURE"=> $has_feed_api ? $config["meta"]["api_signature"] : self::get_signature(),
                        "VERSION"=> "74.0"
                        );

        // build body of request including the bn code (build notation code)                       
        $body = http_build_query($login) . "&BUTTONSOURCE=Rocketgenius_SP" . "&" . $fields;

        //apply filter which allows the query string passed to be altered
        $body = apply_filters("gform_paypalpro_query_{$form['id']}", apply_filters("gform_paypalpro_query", $body, $form, $entry), $form, $entry);

        self::log_debug("Sending request to PayPal - URL: {$url} Request: {$body}");
        $request = new WP_Http();
        $response = $request->post($url, array("sslverify" => false, "ssl" => true, "body" => $body, "timeout" => 20));
        self::log_debug("Response from PayPal: " . print_r($response,true));

        if(is_wp_error($response))
            return false;

        //parsing PayPal response
        parse_str($response["body"], $response);

        if($response["ACK"] != "Success")
            return false;

        return true;
    }

    public static function get_product_billing_data($form, $lead, $config){

        // get products
        $products = GFCommon::get_product_fields($form, $lead);

        $data = array();
        $data["billing"] = array('DESC'=>'');
        $data["products"] = $products;
        $data["amount"] = 0;
        $item = 0;

        //------------------------------------------------------
        //creating line items and recurring description
        $recurring_amount_field = $config["meta"]["recurring_amount_field"];
        foreach($products["products"] as $product_id => $product)
        {
            if(!self::include_in_total($product_id, $config)){
                continue;
            }

            $product_amount = GFCommon::to_number($product["price"]);
            if(is_array(rgar($product,"options"))){
                foreach($product["options"] as $option){
                    $product_amount += $option["price"];
                }
            }
            $data["amount"] += ($product_amount * $product["quantity"]);

            //adding line items
            if($config["meta"]["type"] == "product"){
                $data["billing"]['L_NAME'.$item] = $product["name"];
                $data["billing"]['L_DESC'.$item] = $product["name"];
                $data["billing"]['L_AMT'.$item]  = $product_amount;
                $data["billing"]['L_NUMBER'.$item] = $item+1;
                $data["billing"]['L_QTY'.$item] = $product["quantity"];
            }
            else
            {
                //adding recurring description
                $data["billing"]['DESC'] .=  $item > 1 ? ", " . $product["name"] : $product["name"];
            }

            $item++;
        }

        //adding shipping information if feed is configured for products and services or a subscription based on the form total
        if(!empty($products["shipping"]["name"]) && ($config["meta"]["type"] == "product" || $recurring_amount_field == "all")){
            if($config["meta"]["type"] == "product"){
                $data["billing"]['L_NAME'.$item] = $products["shipping"]["name"];
                $data["billing"]['L_AMT'.$item]  = $products["shipping"]["price"];
                $data["billing"]['L_NUMBER'.$item] = $item+1;
                $data["billing"]['L_QTY'.$item] = 1;
            }
            $data["amount"] += $products["shipping"]["price"];
        }

        $data["line_items"] = $item;

        return $data;
    }

    public static function paypalpro_validation($validation_result){

        $config = self::is_ready_for_capture($validation_result);
        if(!$config)
            return $validation_result;

        require_once(self::get_base_path() . "/data.php");

        // Determine if feed specific api settings are enabled
        $local_api_settings = array();
        if($config["meta"]["api_settings_enabled"] == 1)
             $local_api_settings = self::get_local_api_settings($config);

        $lead = RGFormsModel::create_lead( $validation_result["form"] );

        // Billing
        $card_field = self::get_creditcard_field($validation_result["form"]);
        $card_number = rgpost("input_{$card_field["id"]}_1");
        $card_type = GFCommon::get_card_type($card_number);
        $expiration_date = rgpost("input_{$card_field["id"]}_2");
        $country = rgar( $lead, $config["meta"]["customer_fields"]["country"] );
        $country = class_exists( 'GF_Field_Address' ) ? GF_Fields::get( 'address' )->get_country_code( $country ) : GFCommon::get_country_code( $country );

        $billing = array();
        $billing['CREDITCARDTYPE'] = $card_type["slug"];
        $billing['ACCT'] = $card_number;
        $billing['EXPDATE'] = $expiration_date[0].$expiration_date[1];
        $billing['CVV2'] = rgpost("input_{$card_field["id"]}_3");
        $billing['STREET'] = rgar( $lead, $config["meta"]["customer_fields"]["address1"] );
        $billing['STREET2'] = rgar( $lead, $config["meta"]["customer_fields"]["address2"] );
        $billing['CITY'] = rgar( $lead, $config["meta"]["customer_fields"]["city"] );
        $billing['STATE'] = rgar( $lead, $config["meta"]["customer_fields"]["state"] );
        $billing['ZIP'] = rgar( $lead, $config["meta"]["customer_fields"]["zip"] );
        $billing['COUNTRYCODE'] = $country == "UK" ? "GB" : $country;
        $billing['CURRENCYCODE'] = GFCommon::get_currency();

        // Customer Contact
        $billing['FIRSTNAME'] = rgar( $lead, $config["meta"]["customer_fields"]["first_name"] );
        $billing['LASTNAME'] = rgar( $lead, $config["meta"]["customer_fields"]["last_name"] );
        $billing['EMAIL'] = rgar( $lead, $config["meta"]["customer_fields"]["email"] );

        $product_billing_data = self::get_product_billing_data($validation_result["form"], $lead, $config);
        $amount = $product_billing_data["amount"];
        $products = $product_billing_data["products"];
        $billing = array_merge($billing, $product_billing_data["billing"]);

        if($config["meta"]["type"] == "product"){

            if($amount == 0){
                //blank out credit card field if this is the last page
                if(self::is_last_page($validation_result["form"])){
                    $_POST["input_{$card_field["id"]}_1"] = "";
                }

                //creating dummy transaction response if there are any visible product fields in the form
                if(self::has_visible_products($validation_result["form"])){
                    self::$transaction_response = array("transaction_id" => "N/A", "amount" => 0, "transaction_type" => 1, 'config_id' => $config['id']);
                }

                return $validation_result;
            }

            //setting up a one time payment
            $ip = RGFormsModel::get_ip();
            $billing['PAYMENTACTION']            = "Sale";
            $billing['IPADDRESS']                = $ip == "::1" ? "127.0.0.1" : $ip;
            $billing['RETURNFMFDETAILS']        = "1";
            $billing['BUTTONSOURCE']            = 'gravityforms';
            $billing['AMT']                    = $amount;
            $billing['NOTIFYURL'] = get_bloginfo("url") . "/?page=gf_paypalpro_ipn";

			self::log_debug("Sending one time payment.");
			$response = self::post_to_paypal("DoDirectPayment",$billing,$local_api_settings, $validation_result["form"], $lead);

            if(!empty($response) && !empty($response["TRANSACTIONID"])){
                self::$transaction_response = array("transaction_id" => $response["TRANSACTIONID"], "subscription_amount" => 0, "initial_payment_amount" => $response["AMT"], "transaction_type" => 1, 'config_id' => $config['id']);
				self::log_debug("Payment successful.");
                return $validation_result;
            }
            else
            {
                // Payment was not succesful, need to display error message
                self::log_error("Payment was NOT successful.");
                return self::set_validation_result($validation_result, $_POST, $response, "capture");
            }
        }
        else
        {
            //setting up a recurring payment
            $billing['PROFILESTARTDATE'] = gmdate(DATE_ATOM);
            $billing['SUBSCRIBERNAME'] = $billing['FIRSTNAME'] . " " . $billing['LASTNAME'];
            $billing['MAXFAILEDPAYMENTS'] = "0";

            $interval_unit = self::get_interval_unit($config["meta"]["billing_cycle_type"]);
            $interval_length = $config["meta"]["billing_cycle_number"];

            $billing['BILLINGPERIOD'] = $interval_unit;
            $billing['BILLINGFREQUENCY'] = $interval_length;
            $billing['TOTALBILLINGCYCLES'] =  $config["meta"]["recurring_times"];
            $billing['AMT'] = $amount;

            //setup fee
            $setup_fee_amount = 0;
            if($config["meta"]["setup_fee_enabled"])
            {
                $setup_fee_product = rgar($products["products"], $config["meta"]["setup_fee_amount_field"]);
                if(!empty($setup_fee_product)){
                    $setup_fee_amount = self::get_product_price($setup_fee_product);
                    $billing['INITAMT'] = $setup_fee_amount;
                }
            }

            //trial
            $trial_amount = 0;
            if($config["meta"]["trial_period_enabled"] )
            {
                if($config["meta"]["trial_type"] == "paid")
                {
                    $trial_product = rgar($products["products"], $config["meta"]["trial_amount_field"]);
                    $trial_amount = empty($trial_product) ? 0 : self::get_product_price($trial_product);
                    $billing["TRIALAMT"] = $trial_amount;
                }
                $billing["TRIALBILLINGPERIOD"] = self::get_interval_unit($config["meta"]["trial_period_type"]);
                $billing["TRIALBILLINGFREQUENCY"] = $config["meta"]["trial_period_number"];;
                $billing["TRIALTOTALBILLINGCYCLES"] = $config["meta"]["trial_recurring_times"];
            }
			self::log_debug("Sending recurring payment to PayPal.");
            $response = self::post_to_paypal("CreateRecurringPaymentsProfile",$billing,$local_api_settings, $validation_result["form"], $lead);
             if(!empty($response) && !empty($response["PROFILEID"])){
                self::$transaction_response = array("transaction_id" => rgar($response,"TRANSACTIONID"), "subscription_id" => $response["PROFILEID"],  "subscription_amount" => $billing['AMT'], "initial_payment_amount" => $setup_fee_amount, "transaction_type" => 2, 'config_id' => $config['id'] );
				self::log_debug("Recurring payment setup successful.");
                return $validation_result;
            }
            else
            {
                // Payment was not successful, need to display error message
                self::log_error("Recurring payment was NOT successful.");
                return self::set_validation_result($validation_result, $_POST, $response, "recurring");
            }

        }
    }

    private static function include_in_total($product_id, $config){

        //always include all products in a product feed
        if ($config["meta"]["type"] == "product")
            return true;

        $recurring_field = $config["meta"]["recurring_amount_field"];
        if($recurring_field == $product_id){
            return true;
        }
        else if($recurring_field == "all"){

            //don't use field that is mapped to the trial
            if($config["meta"]["trial_period_enabled"] && $config["meta"]["trial_type"] == "paid" && $config["meta"]["trial_amount_field"] == $product_id)
                return false;
            //don't use field that is mapped to the setup fee
            else if($config["meta"]["setup_fee_enabled"] && $config["meta"]["setup_fee_amount_field"] == $product_id)
                return false;
            else
                return true;
        }

        return false;
    }

    private static function get_product_price($product){
        $amount = GFCommon::to_number($product["price"]);
        if(is_array(rgar($product,"options"))){
            foreach($product["options"] as $option){
                $amount += GFCommon::to_number($option["price"]);
            }
        }

        $amount *= $product["quantity"];
        return $amount;
    }

    private static function get_interval_unit($abbrev){
        switch($abbrev){
            case "D" :
                $interval_unit = "Day";
            break;
            case "W" :
                $interval_unit = "Week";
            break;
            case "M" :
                $interval_unit = "Month";
            break;
            default :
                $interval_unit = "Year";
        }

        return $interval_unit;
    }

    private static function set_validation_result($validation_result,$post,$response,$responsetype){

        $message = "";
        $code = $response["L_ERRORCODE0"];
		$error_long_message = rgar($response,"L_LONGMESSAGE0");
        if($responsetype == "capture")
        {
            switch($code){
                case "15005" :
                case "15006" :
                    $message = __("<!-- Payment Error: " . $code . " -->This credit card has been declined by your bank. Please use another form of payment.", "gravityforms");
                break;

                case "10508" :
                    $message = __("<!-- Payment Error: " . $code . " -->The credit card has expired.", "gravityforms");
                break;

                case "10510" :
                    $message = __("<!-- Payment Error: " . $code . " -->The merchant does not accept this type of credit card.", "gravityforms");
                break;

                case "10502" :
                case "10504" :
                case "10508" :
                case "10519" :
                case "10521" :
                case "10527" :
                    $message = __("<!-- Payment Error: " . $code . " -->There was an error processing your credit card. Please verify the information and try again.", "gravityforms");
                break;

                default :
                    $message = __("<!-- Payment Error: " . $code . " -->There was an error processing your request. Your credit card was not charged. Please try again.", "gravityforms");
            }
        }
        else
        {
            $message = __("<!-- Subscription Error: " . $code . " Message: " . $error_long_message . " -->There was an error processing your request. Your credit card was not charged. Please try again.", "gravityforms");
        }
        self::log_debug("Validation result - Error code: {$code} Message: {$message}");

        foreach($validation_result["form"]["fields"] as &$field)
        {
            if($field["type"] == "creditcard")
            {
                $field["failed_validation"] = true;
                $field["validation_message"] = $message;
                break;
             }

        }
        $validation_result["is_valid"] = false;
        return $validation_result;
    }

    public static function paypalpro_after_submission($entry, $form){
        $payment_method = self::get_payment_method();

        if(empty(self::$transaction_response) && $payment_method != "paypalpro")
            return; //other feed being used

        //updating form meta with current feed id
        gform_update_meta($entry["id"], "paypalpro_feed_id", self::$transaction_response['config_id']);

        //updating form meta with current payment gateway
        gform_update_meta($entry["id"], "payment_gateway", "paypalpro");

        //updating form meta with current payment method
        gform_update_meta($entry["id"], "payment_method", self::get_payment_method());

        if($payment_method == "paypalpro"){
            //updating lead's payment_status to Processing
            RGFormsModel::update_lead_property($entry["id"], "payment_status", 'Processing');
        }
        else if(!empty(self::$transaction_response)){
            $is_recurring = self::$transaction_response["transaction_type"] == 2;
            self::confirm_payment($entry, $form, rgar(self::$transaction_response,"subscription_id"), self::$transaction_response["transaction_id"], $is_recurring , rgar(self::$transaction_response,"initial_payment_amount"), rgar(self::$transaction_response,"subscription_amount"), false, true);
        }
    }

    public static function confirm_payment($entry, $form, $subscriber_id, $transaction_id, $is_recurring, $initial_payment_amount, $subscription_amount, $is_pending, $is_fulfilled=false){

        $entry_txn_id = $is_recurring ? $subscriber_id : $transaction_id;
        $entry_payment_amount = $is_recurring ? $subscription_amount : $initial_payment_amount;
        self::update_entry($entry, $form, $entry_txn_id, $is_recurring, $entry_payment_amount, $is_pending);

        //creating transaction
        $transaction_type = $is_recurring ? "signup" : "payment";

        $config = self::get_config_by_entry($entry["id"]);

        GFPayPalProData::insert_transaction($entry["id"], $config["id"], $transaction_type, $subscriber_id, $transaction_id, "", $initial_payment_amount);

        if(!$is_pending && !$is_fulfilled){
            //fulfilling order (sending notification, creating post, etc...)
            self::fulfill_order($entry, $transaction_id, $initial_payment_amount, $subscription_amount);
        }

        if($is_fulfilled){
            do_action("gform_paypalpro_fulfillment", $entry, $config, $transaction_id, $initial_payment_amount, $subscription_amount);
        }
    }

    public static function update_entry(&$entry, $form, $transaction_id, $is_recurring, $amount, $is_pending){

        //updating entry
        $entry["currency"] = GFCommon::get_currency();
        if($is_pending)
            $entry["payment_status"] = "Pending";
        else
            $entry["payment_status"] = $is_recurring ? "Active" : "Approved";

        $entry["status"] = "active";
        $entry["payment_amount"] = $amount;
        $entry["payment_date"] = gmdate("Y-m-d H:i:s");
        $entry["transaction_id"] = $transaction_id;
        $entry["transaction_type"] = $is_recurring ? "2" : "1";
        $entry["is_fulfilled"] = !$is_pending;
        GFAPI::update_entry($entry);
    }

    public static function process_ipn($wp){

        //Ignore requests that are not IPN
        if(RGForms::get("page") != "gf_paypalpro_ipn")
            return;

        self::log_debug("IPN request received. Starting to process...");
        self::log_debug(print_r($_POST, true));

        //Send request to paypalpro and verify it has not been spoofed
        $bypass_ipn_verification = defined("GF_VERIFY_IPN") && !GF_VERIFY_IPN;
        if(!$bypass_ipn_verification && !self::verify_paypalpro_ipn()){
			self::log_error("IPN request could not be verified by PayPal. Aborting.");
            return;
	 	}

        //Getting subscription id
        if(!rgempty("recurring_payment_id")){
            $entry_id = GFPayPalProData::get_entry_id_by_subscription(rgpost("recurring_payment_id"));
        }
        else if (!rgempty("parent_txn_id")){
            $entry_id = GFPayPalProData::get_entry_id_by_transaction_id(rgpost("parent_txn_id"));
        }
        else{
            $entry_id = GFPayPalProData::get_entry_id_by_transaction_id(rgpost("txn_id"));
        }

        if(empty($entry_id)){
        	self::log_error("Entry ID could not be found. Recurring Payment ID: " . rgpost("recurring_payment_id") . " Parent transaction ID: " .  rgpost("parent_txn_id") . " - Aborting.");
            return;
		}

        //Getting entry associated with this IPN message
        $entry = RGFormsModel::get_lead($entry_id);

        //Ignore orphan IPN messages (ones without an entry)
        if(!$entry)
        {
         	self::log_error("Entry could not be found. Entry ID: {$entry_id}. Aborting.");
            return;
		}
		self::log_debug("Entry has been found." . print_r($entry, true));

        //Getting feed config
        $config = self::get_config_by_entry($entry["id"]);

        //Ignore IPN messages from forms that are no longer configured with the PayPal Pro add-on
        if(!$config){
        	self::log_error("Form no longer configured with PayPal Pro Addon. Form ID: {$entry["form_id"]}. Aborting.");
            return;
		}
		self::log_debug("Form {$entry["form_id"]} is properly configured.");

        //Only process test messages coming fron SandBox and only process production messages coming from production PayPal Pro
        if( (self::get_mode() == "test" && !RGForms::post("test_ipn")) || (self::get_mode() == "production" && RGForms::post("test_ipn")))
        {
        	self::log_error("Invalid test/production mode. IPN message mode (test/production) does not match mode configured in the PayPal feed. Configured Mode: " . self::get_mode() . " IPN test mode: " . RGForms::post("test_ipn"));
            return;
		}

        //Pre IPN processing filter. Allows users to cancel IPN processing
        $cancel = apply_filters("gform_paypalpro_pre_ipn", false, $_POST, $entry, $config);

        if(!$cancel){
        	self::log_debug("Setting payment status...");
            self::set_payment_status($config, $entry, RGForms::post("payment_status"), RGForms::post("txn_type"), RGForms::post("txn_id"), RGForms::post("recurring_payment_id"), RGForms::post("mc_gross"), RGForms::post("profile_status"), trim(RGForms::post("period_type")), RGForms::post("initial_payment_status"), RGForms::post("initial_payment_amount"), RGForms::post("initial_payment_txn_id"), RGForms::post("parent_txn_id"), RGForms::post("reason_code"));
		}
		else{
			self::log_debug("Processing canceled by the gform_paypalpro_pre_ipn filter. Aborting.");
		}

        //Post IPN processing action
        self::log_debug("Before gform_paypalpro_post_ipn.");
        do_action("gform_paypalpro_post_ipn", $_POST, $entry, $config, $cancel);

        self::log_debug("IPN processing complete.");
    }

    public static function set_payment_status($config, $entry, $status, $transaction_type, $transaction_id, $subscriber_id, $amount, $profile_status, $period_type, $initial_payment_status, $initial_payment_amount, $initial_payment_transaction_id, $parent_transaction_id, $reason_code){
        global $current_user;
        $user_id = 0;
        $user_name = "System";
        if($current_user && $user_data = get_userdata($current_user->ID)){
            $user_id = $current_user->ID;
            $user_name = $user_data->display_name;
        }

        switch(strtolower($transaction_type)){
            case "recurring_payment_profile_created":

                if($profile_status == "Active" && $entry["payment_status"] == "Pending"){

                    //Adding note
                    RGFormsModel::add_note($entry["id"], $user_id, $user_name, __("Pending profile has been approved by PayPal and this subscription has been marked as Active.", "gravityformspaypalpro"));

                    //Marking entry as Active
                    $entry["payment_status"] = "Active";
                    GFAPI::update_entry($entry);

                    //Update transaction with transaction_id and payment amount
                    $transactions = GFPayPalProData::get_transactions("signup", $subscriber_id);
                    if(count($transactions) > 0){
                        $transaction = $transactions[0];
                        $transaction["transaction_id"] = rgpost("initial_payment_txn_id");
                        $transaction["amount"] = rgpost("initial_payment_amount");
                        GFPayPalProData::update_transaction($transaction);
                    }
                    else{
                        //this shoulndn't happen, but create a new transaction if one isn't there
                        $feed_id = gform_get_meta($entry["id"], "paypalpro_feed_id");
                        GFPayPalProData::insert_transaction($entry["id"], $feed_id, "signup", $subscriber_id, $transaction_id, $parent_transaction_id, $initial_payment_amount );
                    }

                    //fulfilling order
                    self::fulfill_order($entry, $subscriber_id, $initial_payment_amount, $amount);
                }

            break;

            case "recurring_payment" :
                if($amount <> 0){
                    $do_fulfillment = false;
                    if($profile_status == "Active") {
                        if($period_type == "Trial")
                            RGFormsModel::add_note($entry["id"], $user_id, $user_name, sprintf(__("Trial payment has been made. Amount: %s. Transaction Id: %s", "gravityforms"), GFCommon::to_money($amount, $entry["currency"]), $transaction_id));
                        else
                            RGFormsModel::add_note($entry["id"], $user_id, $user_name, sprintf(__("Subscription payment has been made. Amount: %s. Transaction Id: %s", "gravityforms"), GFCommon::to_money($amount, $entry["currency"]), $transaction_id));

                        //Setting entry to Active
                        if($entry["payment_status"] == "Pending"){
                            RGFormsModel::add_note($entry["id"], $user_id, $user_name, __("Pending profile has been approved by PayPal and this subscription has been marked as Active.", "gravityformspaypalpro"));
                            $entry["payment_status"] = "Active";
                            $do_fulfillment = true;
                        }
                    }
                    else if ($profile_status == "Expired"){
                        $entry["payment_status"] = "Expired";
                        RGFormsModel::add_note($entry["id"], $user_id, $user_name, sprintf(__("Subscription has successfully completed its billing schedule. Subscriber Id: %s", "gravityformspaypalpro"), $subscriber_id));
                    }

                    GFAPI::update_entry($entry);
                    GFPayPalProData::insert_transaction($entry["id"], $config["id"], "payment", $subscriber_id, $transaction_id, $parent_transaction_id, $amount);

                    //fulfilling order
                    if($do_fulfillment){
                        self::fulfill_order($entry, $subscriber_id, $initial_payment_amount, $amount);
                    }
                }
            break;

            case "recurring_payment_failed" :

                if($profile_status == "Active") {
                    $entry["payment_status"] = "Failed";
                    RGFormsModel::add_note($entry["id"], $user_id, $user_name, sprintf(__("Subscription payment failed due to a transaction decline, rejection, or error. The gateway will retry to collect payment in the next billing cycle. Subscriber Id: %s", "gravityforms"), $subscriber_id));
                } else if ($profile_status == "Suspended"){
                    $entry["payment_status"] = "Failed";
                    RGFormsModel::add_note($entry["id"], $user_id, $user_name, sprintf(__("Subscription payment failed due to a transaction decline, rejection, or error. Subscriber Id: %s", "gravityformspaypalpro"), $subscriber_id));
                }
                GFAPI::update_entry($entry);
            break;

            case "recurring_payment_profile_cancel" :
                $entry["payment_status"] = "Cancelled";
                GFAPI::update_entry($entry);
                RGFormsModel::add_note($entry["id"], $user_id, $user_name, sprintf(__("Subscription has been cancelled. Subscriber Id: %s", "gravityformspaypalpro"), $subscriber_id));
            break;


            case "recurring_payment_suspended_due_to_max_failed_payment":
                $entry["payment_status"] = "Failed";
                GFAPI::update_entry($entry);
                RGFormsModel::add_note($entry["id"], $user_id, $user_name, sprintf(__("Subscription is currently suspended as it exceeded maximum number of failed payments allowed. Subscriber Id: %s", "gravityformspaypalpro"), $subscriber_id));

            break;



            default:

                //handles products and donation
                switch(strtolower($status)){

                    case "reversed" :
                        //self::$log->LogDebug("Processing reversal.");
                        if($entry["payment_status"] != "Reversed"){
                            if($entry["transaction_type"] == 1){
                                $entry["payment_status"] = "Reversed";
                                ////self::$log->LogDebug("Setting entry as Reversed");
                                GFAPI::update_entry($entry);
                            }
                            RGFormsModel::add_note($entry["id"], $user_id, $user_name, sprintf(__("Payment has been reversed. Transaction Id: %s. Reason: %s", "gravityformspaypalpro"), $transaction_id, self::get_reason($reason_code)));
                        }

                        GFPayPalProData::insert_transaction($entry["id"], $config["id"], "reversal", $subscriber_id, $transaction_id, $parent_transaction_id, $amount);
                    break;

                    case "canceled_reversal" :
                        //self::$log->LogDebug("Processing a reversal cancellation");
                        if($entry["payment_status"] != "Approved"){
                            if($entry["transaction_type"] == 1){
                                $entry["payment_status"] = "Approved";
                                //self::$log->LogDebug("Setting entry as approved");
                                GFAPI::update_entry($entry);
                            }
                            RGFormsModel::add_note($entry["id"], $user_id, $user_name, sprintf(__("Payment reversal has been canceled and the funds have been transferred to your account. Transaction Id: %s", "gravityformspaypalpro"), $entry["transaction_id"]));
                        }

                        GFPayPalProData::insert_transaction($entry["id"], $config["id"], "reinstated", $subscriber_id, $transaction_id, $parent_transaction_id, $amount);
                    break;

                    case "refunded" :
                        //self::$log->LogDebug("Processing a Refund request.");
                        if($entry["payment_status"] != "Refunded"){
                            if($entry["transaction_type"] == 1){
                                $entry["payment_status"] = "Refunded";
                                //self::$log->LogDebug("Setting entry as Refunded.");
                                GFAPI::update_entry($entry);
                            }
                            RGFormsModel::add_note($entry["id"], $user_id, $user_name, sprintf(__("Payment has been refunded. Refunded amount: %s. Transaction Id: %s", "gravityformspaypalpro"), $amount, $transaction_id));
                        }

                        GFPayPalProData::insert_transaction($entry["id"], $config["id"], "refund", $subscriber_id, $transaction_id, $parent_transaction_id, $amount);
                    break;

                    case "voided" :
                        //self::$log->LogDebug("Processing a Voided request.");
                        if($entry["payment_status"] != "Voided"){
                            if($entry["transaction_type"] == 1){
                                $entry["payment_status"] = "Voided";
                                //self::$log->LogDebug("Setting entry as Voided.");
                                GFAPI::update_entry($entry);
                            }
                            RGFormsModel::add_note($entry["id"], $user_id, $user_name, sprintf(__("Authorization has been voided. Transaction Id: %s", "gravityformspaypalpro"), $transaction_id));
                        }

                        GFPayPalProData::insert_transaction($entry["id"], $config["id"], "void", $subscriber_id, $transaction_id, $parent_transaction_id, $amount);
                    break;

                }

            break;

        }


    }

    private static function get_reason($code){

        switch(strtolower($code)){
            case "adjustment_reversal":
                return __("Reversal of an adjustment", "gravityforms");
            case "buyer-complaint":
                return __("A reversal has occurred on this transaction due to a complaint about the transaction from your customer.", "gravityforms");

            case "chargeback":
                return __("A reversal has occurred on this transaction due to a chargeback by your customer.", "gravityforms");

            case "chargeback_reimbursement":
                return __("Reimbursement for a chargeback.", "gravityforms");

            case "chargeback_settlement":
                return __("Settlement of a chargeback.", "gravityforms");

            case "guarantee":
                return __("A reversal has occurred on this transaction due to your customer triggering a money-back guarantee.", "gravityforms");

            case "other":
                return __("Non-specified reason.", "gravityforms");

            case "refund":
                return __("A reversal has occurred on this transaction because you have given the customer a refund.", "gravityforms");

            default:
                return empty($code) ? __("Reason has not been specified. For more information, contact PayPal Customer Service.", "gravityforms") : $code;
        }
    }

    private static function verify_paypalpro_ipn(){

        //read the post from PayPal Pro system and add 'cmd'
        $req = 'cmd=_notify-validate';
        foreach ($_POST as $key => $value) {
            $value = urlencode(stripslashes($value));
            $req .= "&$key=$value";
        }
        $url = RGForms::post("test_ipn") ? "https://www.sandbox.paypal.com/cgi-bin/websrc" : "https://www.paypal.com/cgi-bin/websrc";
        $url_info = parse_url($url);

        //Post back to PayPal system to validate
        $request = new WP_Http();
        $headers = array("Host" => $url_info["host"]);
        $response = $request->post($url, array("httpversion"=>"1.1", "headers" => $headers, "sslverify" => false, "ssl" => true, "body" => $req, "timeout"=>20));
        self::log_debug("Response: " . print_r($response, true));

        return !is_wp_error($response) && trim($response["body"]) == "VERIFIED";
    }

    public static function uninstall(){

        //loading data lib
        require_once(self::get_base_path() . "/data.php");

        if(!GFPayPalPro::has_access("gravityforms_paypalpro_uninstall"))
            die(__("You don't have adequate permission to uninstall the PayPal Pro Add-On.", "gravityformspaypalpro"));

        //droping all tables
        GFPayPalProData::drop_tables();

        //removing options
        delete_option("gf_paypalpro_version");
        delete_option("gf_paypalpro_settings");

        //Deactivating plugin
        $plugin = "gravityformspaypalpro/paypalpro.php";
        deactivate_plugins($plugin);
        update_option('recently_activated', array($plugin => time()) + (array)get_option('recently_activated'));
    }

    private static function is_gravityforms_installed(){
        return class_exists("RGForms");
    }

    private static function is_gravityforms_supported(){
        if(class_exists("GFCommon")){
            $is_correct_version = version_compare(GFCommon::$version, self::$min_gravityforms_version, ">=");
            return $is_correct_version;
        }
        else{
            return false;
        }
    }

    protected static function has_access($required_permission){
        $has_members_plugin = function_exists('members_get_capabilities');
        $has_access = $has_members_plugin ? current_user_can($required_permission) : current_user_can("level_7");
        if($has_access)
            return $has_members_plugin ? $required_permission : "level_7";
        else
            return false;
    }

    private static function get_customer_information($form, $config=null){

        //getting list of all fields for the selected form
        $form_fields = self::get_form_fields($form);

        $str = "<table cellpadding='0' cellspacing='0'><tr><td class='paypalpro_col_heading'>" . __("PayPal Pro Fields", "gravityformspaypalpro") . "</td><td class='paypalpro_col_heading'>" . __("Form Fields", "gravityformspaypalpro") . "</td></tr>";
        $customer_fields = self::get_customer_fields();
        foreach($customer_fields as $field){
            $selected_field = $config ? $config["meta"]["customer_fields"][$field["name"]] : "";
            $str .= "<tr><td class='paypalpro_field_cell'>" . $field["label"]  . "</td><td class='paypalpro_field_cell'>" . self::get_mapped_field_list($field["name"], $selected_field, $form_fields) . "</td></tr>";
        }
        $str .= "</table>";

        return $str;
    }

    private static function get_customer_fields(){
        return array(array("name" => "first_name" , "label" => "First Name"), array("name" => "last_name" , "label" =>"Last Name"),
        array("name" => "email" , "label" =>"Email"), array("name" => "address1" , "label" =>"Billing Address"), array("name" => "address2" , "label" =>"Billing Address 2"),
        array("name" => "city" , "label" =>"Billing City"), array("name" => "state" , "label" =>"Billing State"), array("name" => "zip" , "label" =>"Billing Zip"),
        array("name" => "country" , "label" =>"Billing Country"));
    }

    private static function get_mapped_field_list($variable_name, $selected_field, $fields){
        $field_name = "paypalpro_customer_field_" . $variable_name;
        $str = "<select name='$field_name' id='$field_name'><option value=''></option>";
        foreach($fields as $field){
            $field_id = $field[0];
            $field_label = esc_html(GFCommon::truncate_middle($field[1], 40));

            $selected = $field_id == $selected_field ? "selected='selected'" : "";
            $str .= "<option value='" . $field_id . "' ". $selected . ">" . $field_label . "</option>";
        }
        $str .= "</select>";
        return $str;
    }

    private static function get_product_options($form, $selected_field, $form_total){
        $str = "<option value=''>" . __("Select a field", "gravityformspaypalpro") ."</option>";
        $fields = GFCommon::get_fields_by_type($form, array("product"));

        foreach($fields as $field){
            $field_id = $field["id"];
            $field_label = RGFormsModel::get_label($field);

            $selected = $field_id == $selected_field ? "selected='selected'" : "";
            $str .= "<option value='" . $field_id . "' ". $selected . ">" . $field_label . "</option>";
        }

        if($form_total){
            $selected = $selected_field == 'all' ? "selected='selected'" : "";
            $str .= "<option value='all' " . $selected . ">" . __("Form Total", "gravityformspaypalpro") ."</option>";
        }

        return $str;
    }

    private static function get_form_fields($form){
        $fields = array();

        if(is_array($form["fields"])){
            foreach($form["fields"] as $field){
                if(isset($field["inputs"]) && is_array($field["inputs"])){

                    foreach($field["inputs"] as $input)
                        $fields[] =  array($input["id"], GFCommon::get_label($field, $input["id"]));
                }
                else if(!rgar($field, 'displayOnly')){
                    $fields[] =  array($field["id"], GFCommon::get_label($field));
                }
            }
        }
        return $fields;
    }

    private static function is_paypalpro_page(){
        $current_page = trim(strtolower(RGForms::get("page")));
        return in_array($current_page, array("gf_paypalpro"));
    }

	public static function set_logging_supported($plugins)
	{
		$plugins[self::$slug] = "PayPal Pro";
		return $plugins;
	}

	private static function log_error($message){
		if(class_exists("GFLogging"))
		{
			GFLogging::include_logger();
			GFLogging::log_message(self::$slug, $message, KLogger::ERROR);
		}
	}

	private static function log_debug($message){
		if(class_exists("GFLogging"))
		{
			GFLogging::include_logger();
			GFLogging::log_message(self::$slug, $message, KLogger::DEBUG);
		}
	}

    //Returns the url of the plugin's root folder
    public static function get_base_url(){
        return plugins_url(null, __FILE__);
    }

    //Returns the physical path of the plugin's root folder
    public static function get_base_path(){
        $folder = basename(dirname(__FILE__));
        return WP_PLUGIN_DIR . "/" . $folder;
    }

	public static function disable_entry_info_payment( $is_enabled, $entry ) {

		$config = self::get_config_by_entry( $entry['id'] );

		return $config ? false : $is_enabled;
	}

	/**
	 * Get version of Gravity Forms database.
	 *
	 * @since  1.7.3
	 * @access public
	 *
	 * @uses   GFFormsModel::get_database_version()
	 *
	 * @return string
	 */
	public static function get_gravityforms_db_version() {

		return method_exists( 'GFFormsModel', 'get_database_version' ) ? GFFormsModel::get_database_version() : GFForms::$version;

	}

	/**
	 * Get name for entry table.
	 *
	 * @since  1.7.3
	 * @access public
	 *
	 * @uses   GFFormsModel::get_entry_table_name()
	 * @uses   GFFormsModel::get_lead_table_name()
	 * @uses   GFPayPalPro::get_gravityforms_db_version()
	 *
	 * @return string
	 */
	public static function get_entry_table_name() {

		return version_compare( self::get_gravityforms_db_version(), '2.3-dev-1', '<' ) ? GFFormsModel::get_lead_table_name() : GFFormsModel::get_entry_table_name();

	}

	/**
	 * Get name for entry meta table.
	 *
	 * @since  1.7.3
	 * @access public
	 *
	 * @uses   GFFormsModel::get_entry_meta_table_name()
	 * @uses   GFFormsModel::get_lead_meta_table_name()
	 * @uses   GFPayPalPro::get_gravityforms_db_version()
	 *
	 * @return string
	 */
	public static function get_entry_meta_table_name() {

		return version_compare( self::get_gravityforms_db_version(), '2.3-dev-1', '<' ) ? GFFormsModel::get_lead_meta_table_name() : GFFormsModel::get_entry_meta_table_name();

	}

}

?>

<?php
/*
Plugin Name: Shopp Coupons
Version: 1.0.7
Description: Generates one time use coupon codes. Will delete after use. This plugin is part of the <a href="http://shopptoolbox.com">Shopp Toolbox</a>
Plugin URI: http://shopptoolbox.com
Author: Shopp Toolbox
Author URI: http://shopptoolbox.com

	This plugin is free software: you can redistribute it and/or modify
	it under the terms of the GNU General Public License as published by
	the Free Software Foundation, either version 3 of the License, or
	(at your option) any later version.

	This plugin is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	GNU General Public License for more details.

	You should have received a copy of the GNU General Public License
	along with this plugin.  If not, see <http://www.gnu.org/licenses/>.
*/
if(!defined('ABSPATH')) die();

require('lib/Update.php');
require('lib/welcome.php');

$shopp_coupon = new ShoppCoupon();

class ShoppCoupon{
	var $product = 'shopp-coupons';

	function __construct(){
		add_action('admin_menu', array(&$this, 'add_menu'), 99);	
		add_action('init', array(&$this, 'updater'));
		add_action('admin_notices', array(&$this, 'notices'));
		add_action('shopp_order_success', array(&$this, 'remove_generated_coupon'));

		//To register our CSS file to load later.
        add_action('admin_init', array(&$this, 'register_css'));
	}

	function notices(){
		if(!is_plugin_active('shopp/Shopp.php')){
			echo '<div class="error"><p><strong>Shopp Coupon</strong>: It is highly recommended to have the <a href="http://www.shopplugin.net">Shopp Plugin</a> active before using any of the Shopp Toolbox plugins.</p></div>';
		}

		if(!is_writeable(dirname(__FILE__))){
			echo '<div class="error"><p><strong>Shopp Coupon</strong>: The plugin directory is not writable by the web server. We can not create the coupon list for you to download. Please check your file permissions.</p></div>';
		}
	}

	function register_css(){
		wp_register_style('stb-coupons-css', plugins_url('css/stb-coupon-admin.css', __FILE__));
	}
    function load_css(){
        wp_enqueue_style('stb-coupon-css');
    }

	function add_menu(){
		global $menu;
		$position = 52;
		while (isset($menu[$position])) $position++;

		if(!$this->toolbox_menu_exist()){
			add_menu_page('Shopp Toolbox', 'Shopp Toolbox', 'shopp_menu', 'shopp-toolbox', array('ShoppToolbox_Welcome', 'display_welcome'), plugin_dir_url(__FILE__) . 'img/toolbox.png', $position);
			$page = add_submenu_page('shopp-toolbox', 'Shopp Toolbox', 'Get Started', 'shopp_menu', 'shopp-toolbox', array('ShoppToolbox_Welcome', 'display_welcome'));
	        add_action( 'admin_print_styles-'.$page, array(&$this, 'load_css'));
		}

		$page = add_submenu_page('shopp-toolbox', 'Coupons', 'Coupons', 'shopp_menu', 'shopp-coupon', array(&$this, 'display_settings'));
        add_action( 'admin_print_styles-'.$page, array(&$this, 'load_css'));

	}
	
	function toolbox_menu_exist(){
        global $menu;

        $return = false;
        foreach($menu as $menus => $item){
            if($item[0] == 'Shopp Toolbox'){
                $return = true;
            }
        }
        return $return;
    }

	function updater(){
        $args = array(
            'basename' => plugin_basename( __FILE__ ), //required
            'product_name' => $this->product,  //post slug - must match
        );
        new ShoppToolbox_Updater($args);
	}

	function remove_generated_coupon($Purchase){
		global $wpdb;
		$options = get_option('shopp_coupons');

		foreach($Purchase->promos as $promo_id => $coupon_code){
			if(array_key_exists($promo_id, $options)){
				$wpdb->query($wpdb->prepare('DELETE FROM '.$wpdb->prefix.'shopp_promo WHERE id = %s', $promo_id));
				
				//remove this from the array
				unset($options[$promo_id]);			
			}
		}
		update_option('shopp_coupons', $options);
	}

	function generate_xls($data){
		$file = dirname(__FILE__) . '/generated_coupons.csv';

		$header = array('Coupon ID', 'Coupon Code');

		$this->mssafe_csv($file, $data, $header);

		if(file_exists($file)){
			return $file;
		}else{
			return false;
		}
	}

	function get_datetime($timestamp = false){
		if($timestamp){
			return date('Y-m-d G:i:s', $timestamp);
		}else{
			return date('Y-m-d G:i:s');
		}
		
	}

	function generate_coupons($type = 'Amount Off', $amount, $count = '1', $prefix) {
		global $wpdb;
		$options = get_option('shopp_coupons');
		$type_whitelist = array('Amount Off', 'Percentage Off');

		$new_codes = array();

		for($i = 1; $i <= $count; $i++){
			$coupon_code = ($prefix ? esc_attr($prefix) : '') . substr(sha1(time().mt_rand()), 0, 10);

			$wpdb->insert($wpdb->prefix.'shopp_promo', 
				array(
					'name' => $coupon_code,
					'status' => 'enabled',
					'type' => (in_array($type, $type_whitelist) ? $type : 'Amount Off'),
					'target' => 'Cart',
					'discount' => (float)$amount,
					'buyqty' => '0',
					'getqty' => '0',
					'uses' => '0',
					'search' => 'all',
					'rules' => serialize(
						array(
							1 => array(
								'property' => 'Promo code',
								'logic' => 'Is equal to',
								'value' => $coupon_code
							),
							2 => array(
								'property' => 'Promo use count',
								'logic' => 'Is less than',
								'value' => '1'
							)
						)
					),
					'starts' => '1970-01-01 00:00:01',
					'ends' => '1970-01-01 00:00:01',
					'created' => $this->get_datetime(),
					'modified' => $this->get_datetime()
				)
			);
			$new_codes[$wpdb->insert_id] = array('id' => $wpdb->insert_id, 'code' => $coupon_code);
		}

		update_option('shopp_coupons', (!empty($options) ? array_merge($options, $new_codes) : $new_codes));

		return $new_codes;
	}

	function display_settings(){
		if(isset($_REQUEST['generate_coupons']) && wp_verify_nonce($_REQUEST['stb_coupon_nonce'], 'nonce_generate_coupons')){

			$data = $_REQUEST['data'];

			if(!empty($data['amount']) && !empty($data['type']) && !empty($data['count'])){
				$results = $this->generate_coupons($data['type'], $data['amount'], $data['count'], $data['prefix']);

				if($data['download'] == 'yes'){
					$file = $this->generate_xls($results);

					if($file){
						$url = plugins_url('generated_coupons.csv', __FILE__);
						echo '<div class="updated fade"><p>Your coupons have been generated. <a href="'.$url.'">Download the list here.</a> (Right Click and Save As)</p></div>';	
					}else{
						echo '<div class="updated fade"><p>Your coupons have been generated, however we could not create the XLS file. Check your file permissions.</p></div>';
					}

				}else{
					echo '<div class="updated fade"><p>Your coupons have been generated.</p></div>';			
				}

			}else{
				echo '<div class="error"><p>Did you fill out the form?</p></div>';
			}
		}
?>
		<div class="wrap">
	        <h2>Shopp Coupons</h2>
	        <div class="description">
	            <p>This plugin gives Shopp the ability to generate one time use "coupon" codes to be used at checkout for a percentage or amount off of the total order (cart). </p>
	        </div>
	        <div class="metabox-holder">
		        <div class="postbox">
		            <div class="handlediv" title="Click to toggle">
		                <br />
		            </div>
		            <h3 class="hndle"><span>Generate Coupons</span></h3>
		            <div class="inside">
	           			<form action="" method="post">
			            	<p>
				                <ul>
				                	<li>What type of coupon to you want to create? 
				                		<select name="data[type]">
				                			<option></option>
				                			<option>Amount Off</option>
				                			<option>Percentage Off</option>
				                		</select>
				                	</li>
				                	<li>How much would you like the coupon to be for? <input type="text" name="data[amount]" size="6" value="" /> (Only use numeric characters and separator, ie 5.00 or 5.43. Do not include currency or percentage character)</li>
				                	<li>How many coupons do you want to create? <input type="text" name="data[count]" size="6" value="" /></li>
				                	<li>Download generated coupon list?
				                		<input type="radio" name="data[download]" value="yes" />Yes <input type="radio" name="data[download]" value="no" />No <b>(Can be left blank) </b>
				                	</li>
				                	<li>Would you like to prefix your coupon codes? <input type="text" name="data[prefix]" size="6" value="" /> <b>(Can be left blank)</b>
				                	</li>
			    		        </ul>
			            	</p>
			            	<p><input type="submit" class="button-primary" value="Generate Coupons" /></p>
			            	<input type="hidden" name="generate_coupons" value="true" />
          	                <?php wp_nonce_field('nonce_generate_coupons', 'stb_coupon_nonce'); ?>
			            </form>
		            </div> <!--inside-->
		        </div><!--postbox-->
		    </div><!--metabox-holder-->
	    </div>
<?php	
	}

	function mssafe_csv($filepath, $data, $header = array()){
		//from http://php.net/manual/en/function.fputcsv.php
		if($fp = fopen($filepath, 'w')){ 
			$show_header = true; 
	        if(empty($header)){ 
	        	$show_header = false; 
	            reset($data); 
	            $line = current($data); 
	            if(!empty($line)){ 
	                reset($line); 
	                $first = current($line); 
	                if ( substr($first, 0, 2) == 'ID' && !preg_match('/["\\s,]/', $first) ) { 
	                    array_shift($data); 
	                    array_shift($line); 
	                    if ( empty($line) ) { 
	                        fwrite($fp, "\"{$first}\"\r\n"); 
	                    } else { 
	                        fwrite($fp, "\"{$first}\","); 
	                        fputcsv($fp, split(',', $line));
	                        fseek($fp, -1, SEEK_CUR); 
	                        fwrite($fp, "\r\n"); 
	                    } 
	                } 
	            } 
	        } else { 
	            reset($header); 
	            $first = current($header); 
	            if ( substr($first, 0, 2) == 'ID' && !preg_match('/["\\s,]/', $first) ) { 
	                array_shift($header); 
	                if ( empty($header) ) { 
	                    $show_header = false; 
	                    fwrite($fp, "\"{$first}\"\r\n"); 
	                } else { 
	                    fwrite($fp, "\"{$first}\","); 
	                } 
	            } 
	        } 
	        if ( $show_header ) { 
	            fputcsv($fp, $header); 
	            fseek($fp, -1, SEEK_CUR); 
	            fwrite($fp, "\r\n"); 
	        } 
	        foreach ( $data as $line ) { 
	            fputcsv($fp, $line);
	            fseek($fp, -1, SEEK_CUR); 
	            fwrite($fp, "\r\n"); 
	        } 
	        fclose($fp); 
	    } else { 
	        return false; 
	    } 
	    return true; 
	}
}

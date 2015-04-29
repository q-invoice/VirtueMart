<?php

if (!defined('_VALID_MOS') && !defined('_JEXEC'))
    die('Direct Access to ' . basename(__FILE__) . ' is not allowed.');

/**
 *
 *
 * @author Casper Mekel, q-invoice.com
 * @package VirtueMart
 * @subpackage payment
 * @copyright Copyright (C) 2013 q-invoice.com - All rights reserved.
 * @license http://www.gnu.org/copyleft/gpl.html GNU/GPL, see LICENSE.php
 * VirtueMart is free software. This version may have been modified pursuant
 * to the GNU General Public License, and as distributed it includes or
 * is derivative of works licensed under the GNU General Public License or
 * other free or open source software licenses.
 * See /administrator/components/com_virtuemart/COPYRIGHT.php for copyright notices and details.
 *
 * http://virtuemart.net
 */
if (!class_exists('vmPSPlugin'))
    require(JPATH_VM_PLUGINS . DS . 'vmpsplugin.php');

class plgVmPaymentQinvoiceConnect extends vmPSPlugin {

    public static $_this = false;

    function __construct(& $subject, $config) {

        parent::__construct($subject, $config);
        $varsToPush = $this->getVarsToPush();
        $this->setConfigParameterable($this->_configTableFieldName, $varsToPush);
        if(!isset($this->params)){
            $plugin = JPluginHelper::getPlugin('qinvoiceconnect');
            $this->params = new JParameter( $plugin->params );
        }
    // self::$_this = $this;
    }
    function plgVmOnPaymentResponseReceived(){
        $order_number = JRequest::getVar('order_id');
        $order_code = JRequest::getVar('order_code');

        if(!class_exists('VirtueMartModelOrders'))
        {
            require(JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php');
        }

        $virtuemart_order_id = VirtueMartModelOrders::getOrderIdByOrderNumber($order_number);
        $oModelOrder = new VirtueMartModelOrders();

        $order = $oModelOrder->getOrder($virtuemart_order_id);
      //mail('casper@q-invoice.com','plgVmOnPaymentResponseReceived '. $order_number, serialize($order));

        $invoice_trigger = $this->params->get('invoice_trigger');

        // the trigger?
       // echo $invoice_trigger .'=='. $order['details']['BT']->order_status;
        if($invoice_trigger == $order['details']['BT']->order_status){ 
            // status changed?
            $h = count($order['history']);   
            if($order['history'][($h-2)]->order_status_code != $order['details']['BT']->order_status){
                $this->prepareRequestForQinvoice($order);
            }
        }
    }
    function plgVmConfirmedOrder($cart, $order){
        //mail('casper@q-invoice.com','NEW/CONFIRMED ORDER ');
        $invoice_trigger = $this->params->get('invoice_trigger');
        //echo $invoice_trigger .'=='. $order['details']['BT']->order_status;
        if($invoice_trigger == $order['details']['BT']->order_status){ 
            // status changed?
            $h = count($order['history']);               
                $this->prepareRequestForQinvoice($order);            
        }
        //print_r($order);
        //die('plgVmConfirmedOrder');

        return NULL;
    }


    function plgVmOnUpdateOrderPayment($orders,$new_order_status) {

        // Get the order status name
        $db = JFactory::getDBO();
        $q = 'SELECT `order_status_name` FROM `#__virtuemart_orderstates` WHERE `order_status_code`="'.$orders->order_status.'"';
        $db->setQuery($q);
        $statusName = $db->loadResult();
        
        $invoice_trigger = $this->params->get('invoice_trigger');
        
        $orderModel=VmModel::getModel('orders');
        $order = $orderModel->getOrder($orders->virtuemart_order_id);

        if($invoice_trigger != $orders->order_status){
            // not the right trigger
            //echo('not the trigger');
            return true;
        }
        //echo $order['details']['BT']->order_status_name .' '. $statusName;
        if($order['details']['BT']->order_status_name == $statusName){
            // status wasn't updated
            return true;
        }

        $this->prepareRequestForQinvoice($order,$orders->order_status);

    }

     function prepareRequestForQinvoice($order,$new_order_status = '') {
        
    
        //Get plugin params
        $api_url = $this->params->get('api_url');
        $api_username = $this->params->get('api_username');
        $api_password = $this->params->get('api_password');
        $layout_code = $this->params->get('layout_code');
        $invoice_remark = $this->params->get('invoice_remark');
        
        $invoice_tag = $this->params->get('invoice_tag');
        $invoice_action = $this->params->get('invoice_action');
        $save_relation = $this->params->get('save_relation');

        // echo '<pre>';
        // print_r($order);
        // //print_r($order['items'][0]);
        // echo '</pre>';
        //die();
        
       // mail('casper@q-invoice.com','Qinvoice connect '. $statusName,$old_order_status . serialize($order));

        // Get the country code
        $db = JFactory::getDBO();
        $q = 'SELECT `country_2_code` FROM `#__virtuemart_countries` WHERE `virtuemart_country_id`="'.$order['details']['BT']->virtuemart_country_id.'"';
        $db->setQuery($q);
        $BTcountryCode = $db->loadResult();

        $lang = $order['details']['BT']->order_language;
        $lang = str_replace("-",'_',$lang);
        $lang = strtolower($lang);

        $invoice = new qinvoice($api_username,$api_password,$api_url);

        $invoice->companyname = $order['details']['BT']->company;       // Your customers company name
        $invoice->firstname = $order['details']['BT']->first_name;       // Your customers contact name
        $invoice->lastname = $order['details']['BT']->last_name;       // Your customers contact name
        $invoice->email = $order['details']['BT']->email;                // Your customers emailaddress (invoice will be sent here)
        $invoice->address = $order['details']['BT']->address_1;                // Self-explanatory
        $invoice->zipcode = $order['details']['BT']->zip;              // Self-explanatory
        $invoice->city = $order['details']['BT']->city;                     // Self-explanatory
        $invoice->country = $BTcountryCode;                 // 2 character country code: NL for Netherlands, DE for Germany etc
        $invoice->copy = $lang;
        //$invoice->vatnumber = $rowThree['vat_id'];  
        
        // Get the country code for shipping address
        $db = JFactory::getDBO();
        $q = 'SELECT `country_2_code` FROM `#__virtuemart_countries` WHERE `virtuemart_country_id`="'.$order['details']['ST']->virtuemart_country_id.'"';
        $db->setQuery($q);
        $STcountryCode = $db->loadResult();

        $invoice->delivery_address = $order['details']['ST']->address_1;                // Self-explanatory
        $invoice->delivery_zipcode = $order['details']['ST']->zip;              // Self-explanatory
        $invoice->delivery_city = $order['details']['ST']->city;                     // Self-explanatory
        $invoice->delivery_country = $STcountryCode;      
        
        $invoice->saverelation = $save_relation;
        
        $paid = 0;
        $paid_remark = '';
        
        $h = count($order['history']);   

        if($order['history'][($h-1)]->order_status_code == 'C' || $new_order_status == 'C'){
            $paid = 1;
            $paid_remark = $this->params->get('paid_remark');
        }
        //die($h .' : '. $new_order_status);
        $invoice->paid = $paid;
        $invoice->layout = $invoice_layout;
        $invoice->action = $invoice_action;
        $invoice->addTag($order['details']['BT']->order_number);
        $invoice->addTag($invoice_tag);

  
        $db = JFactory::getDBO();
        $q = 'SELECT CONCAT(`order_weight`,`shipment_weight_unit`) FROM `#__virtuemart_shipment_plg_weight_countries` WHERE `order_number`="'.$order['details']['BT']->order_number.'"';
        $db->setQuery($q);
        $STweight = $db->loadResult();



        $invoice_remark = str_replace('{customer_note}',$order['details']['BT']->customer_note,$invoice_remark);                  // Self-explanatory
        $invoice_remark = str_replace('{order_id}',$order['details']['BT']->order_number,$invoice_remark);
        $invoice_remark = str_replace('{order_weight}',$STweight,$invoice_remark);
        $invoice_remark .= ' '. $paid_remark; 

        $invoice->remark = $invoice_remark;
        //$invoice->remark = str_replace('{order_id}',$order['details']['BT']->order_number,$invoice_remark) .' '. $paid_remark;                  // Self-explanatory

        foreach($order['calc_rules'] as $rule):
            if($rule->virtuemart_order_item_id == ''){
                if($rule->calc_kind == 'shipment' || $rule->calc_kind == 'payment'){
                    $taxrules[$rule->calc_kind] = $rule->calc_value;
                }
            }else{
                if($rule->calc_kind == 'VatTax'){
                    $taxrules[$rule->virtuemart_order_item_id] = $rule->calc_value;
                }
            }
        endforeach;

        // echo '<pre>';
        // print_r($order['items']);
        // echo '</pre>';
        
        // die();
        foreach($order['items'] as $item):
            $attributes = array();
            $attributes = json_decode($item->product_attribute);
            $product_att = '';
            if(is_array($item->customfields)){
                foreach($item->customfields as $customfield ){
                        $product_att .= "\n". $customfield->custom_title .": ". strip_tags($customfield->customfield_value);
                    }
            }else{
                    foreach((array)$attributes as $a => $attr_desc){
                        $product_att .= "\n". strip_tags($attr_desc);
                    }
            }



            $price = $item->product_discountedPriceWithoutTax > 0 ? $item->product_discountedPriceWithoutTax : $item->product_item_price;

            if($taxrules[$item->virtuemart_order_item_id]){
                $vatpercentage = $taxrules[$item->virtuemart_order_item_id];
            }else{
                $vatpercentage = $price > 0 ? round(($item->product_tax / $price)*100) : 0;
            }


            $params = array(    
                    'code' => $item->order_item_sku,
                    'description' => $item->order_item_name . $product_att,
                   // 'price' => ($item->product_discountedPriceWithoutTax - ($item->product_subtotal_discount/$item->product_quantity))*100,
                    'price' => $price*100,
                    'vatpercentage' => $vatpercentage*100,
                  //  'discount' => trim(number_format($arrData[$i]['base_discount_amount'], 2, '.', '')/$arrData[$i]['base_price'])*100,
                    'quantity' => $item->product_quantity*100,
                    'categories' => $item->virtuemart_category_id
                    );
            $invoice->addItem($params);

        endforeach;
        
        if($order['details']['BT']->coupon_discount < 0){
            $params = array(    
                    'code' => '',
                    'description' => 'Coupon: '. $order['details']['BT']->coupon_code,
                   // 'price' => ($item->product_discountedPriceWithoutTax - ($item->product_subtotal_discount/$item->product_quantity))*100,
                    'price' => $order['details']['BT']->coupon_discount*100,
                    'vatpercentage' => 0,
                    'quantity' => 100,
                    'categories' => 'discount'
                    );
            $invoice->addItem($params);
        }

        if($order['details']['BT']->order_shipment > 0){

            // Get the shipment name
            $db = JFactory::getDBO();
            $q = 'SELECT `shipment_name` FROM `#__virtuemart_shipmentmethods_'. $lang .'` WHERE `virtuemart_shipmentmethod_id`="'.$order['details']['BT']->virtuemart_shipmentmethod_id.'"';
            $db->setQuery($q);
            $shipmentName = $db->loadResult();

            $params = array(    
                    'code' => '',
                    'description' => $shipmentName,
                   // 'price' => ($item->product_discountedPriceWithoutTax - ($item->product_subtotal_discount/$item->product_quantity))*100,
                    'price' => $order['details']['BT']->order_shipment*100,
                    'vatpercentage' => $taxrules['shipment']*100,
                    'quantity' => 100,
                    'categories' => 'shipment'
                    );
            $invoice->addItem($params);
        }
        if($order['details']['BT']->order_payment > 0){
            // Get the shipment name
            $db = JFactory::getDBO();
            $q = 'SELECT `payment_name` FROM `#__virtuemart_paymentmethods_'. $lang .'` WHERE `virtuemart_paymentmethod_id`="'.$order['details']['BT']->virtuemart_paymentmethod_id.'"';
            $db->setQuery($q);
            $paymentName = $db->loadResult();

            $params = array(    
                    'code' => '',
                    'description' => $paymentName,
                   // 'price' => ($item->product_discountedPriceWithoutTax - ($item->product_subtotal_discount/$item->product_quantity))*100,
                    'price' => $order['details']['BT']->order_payment*100,
                    'vatpercentage' => $taxrules['payment']*100,
                    'quantity' => 100,
                    'categories' => 'payment'
                    );
            $invoice->addItem($params);
        }

        $result =  $invoice->sendRequest();
        return true;
    }


    /*
     * We must reimplement this triggers for joomla 1.7
     */

    /**
     * Create the table for this plugin if it does not yet exist.
     * This functions checks if the called plugin is active one.
     * When yes it is calling the standard method to create the tables
     * @author Valérie Isaksen
     *
     */
    function plgVmOnStoreInstallPaymentPluginTable($jplugin_id) {
    return $this->onStoreInstallPluginTable($jplugin_id);
    } 
}

class qinvoice{

    protected $gateway = '';
    private $username;
    private $password;

    private $identifier = 'vm25_113';

    public $companyname;
    public $firstname;
    public $lastname;
    public $email;
    public $address;
    public $zipcode;
    public $city;
    public $country;
    public $delivery_address;
    public $delivery_zipcode;
    public $delivery_city;
    public $delivery_country;
    public $vatnumber;
    public $remark;
    public $paid;
    public $action;
    public $copy;
    public $saverelation;

    public $layout;
    
    private $tags = array();
    private $items = array();
    private $files = array();
    private $recurring;

    function __construct($username, $password, $url){
        $this->username = $username;
        $this->password = $password;
        $this->recurring = 'none';
        $this->gateway = $url;
    }

    public function addTag($tag){
        $this->tags[] = $tag;
    }

    public function setLayout($code){
        $this->layout = $code;
    }

    public function setRecurring($recurring){
        $this->recurring = strtolower($recurring);
    }

    public function addItem($params){
        $item['code'] = $params['code'];
        $item['description'] = $params['description'];
        $item['price'] = $params['price'];
        $item['vatpercentage'] = $params['vatpercentage'];
        $item['discount'] = $params['discount'];
        $item['quantity'] = $params['quantity'];
        $item['categories'] = $params['categories'];
        $this->items[] = $item;
    }
    
    public function addFile($name, $url){
        $this->files[] = array('url' => $url, 'name' => $name);
    }

    public function sendRequest() {
        $content = "<?xml version='1.0' encoding='UTF-8'?>";
        $content .= $this->buildXML();

        $headers = array("Content-type: application/atom+xml");
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->gateway );
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 120);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $content);
        $data = curl_exec($ch);
        if (curl_errno($ch)) {
            print curl_error($ch);
        } else {
            curl_close($ch);
        }
        if($data == 1){
            return true;
        }else{
            return false;
        }
        
    }

    private function buildXML(){
        $string = '<request>
                        <login mode="newInvoice">
                            <username><![CDATA['.$this->username.']]></username>
                            <password><![CDATA['.$this->password.']]></password>
                            <identifier><![CDATA['.$this->identifier.']]></identifier>
                        </login>
                        <invoice>
                            <companyname><![CDATA['. $this->companyname .']]></companyname>
                            <firstname><![CDATA['. $this->firstname .']]></firstname>
                            <lastname><![CDATA['. $this->lastname .']]></lastname>
                            <email><![CDATA['. $this->email .']]></email>
                            <address><![CDATA['. $this->address .']]></address>
                            <zipcode><![CDATA['. $this->zipcode .']]></zipcode>
                            <city><![CDATA['. $this->city .']]></city>
                            <country><![CDATA['. $this->country .']]></country>

                            <delivery_address><![CDATA['. $this->delivery_address .']]></delivery_address>
                            <delivery_zipcode><![CDATA['. $this->delivery_zipcode .']]></delivery_zipcode>
                            <delivery_city><![CDATA['. $this->delivery_city .']]></delivery_city>
                            <delivery_country><![CDATA['. $this->delivery_country .']]></delivery_country>

                            <vat><![CDATA['. $this->vatnumber .']]></vat>
                            <recurring><![CDATA['. $this->recurring .']]></recurring>
                            <remark><![CDATA['. $this->remark .']]></remark>
                            <layout><![CDATA['. $this->layout .']]></layout>
                            <paid><![CDATA['. $this->paid .']]></paid>
                            <action><![CDATA['. $this->action .']]></action>
                            <copy><![CDATA['. $this->copy .']]></copy>
                            <saverelation><![CDATA['. $this->saverelation .']]></saverelation>
                            <tags>';
        foreach($this->tags as $tag){
            $string .= '<tag><![CDATA['. $tag .']]></tag>';
        }
                    
        $string .= '</tags>
                    <items>';
        foreach($this->items as $i){

            $string .= '<item>
                <code><![CDATA['. $i['code'] .']]></code>
                <quantity><![CDATA['. $i['quantity'] .']]></quantity>
                <description><![CDATA['. $i['description'] .']]></description>
                <price><![CDATA['. $i['price'] .']]></price>
                <vatpercentage><![CDATA['. $i['vatpercentage'] .']]></vatpercentage>
                <discount><![CDATA['. $i['discount'] .']]></discount>
                <categories><![CDATA['. $i['categories'] .']]></categories>
                
            </item>';
        }
                       
        $string .= '</items>
                    <files>';
        foreach($this->files as $f){
            $string .= '<file url="'.$f['url'].'"><![CDATA['.$f['name'].']]></file>';
        }
        $string .= '</files>
                </invoice>
            </request>';
        return $string;
    }
}

// No closing tag
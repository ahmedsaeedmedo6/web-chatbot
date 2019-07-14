<?php

defined('BASEPATH') OR exit('No direct script access allowed');

$url = rtrim(__DIR__, 'controllers');
require_once $url . 'classes/user.php';

class Subscribe extends CI_Controller {

    private $data;
    private $db_drivers;

    function __construct() {
        parent::__construct();
        // check if user has access permission
        $this->load->library('authentication');
        // loading models
        $this->load->model('subscribeFormMod');
        $this->data = NULL;
        $this->db_drivers = array('mysqli');
    }
    
    private function testConnection($drivers, $config){
        $driver = '';
        if($drivers){
            $driver = $drivers[0];
            $config['dbdriver'] = $driver;
            $db = @$this->load->database($config, TRUE);
            if($db->conn_id) {
                return $driver;
            } else {
                array_shift($drivers);
                $driver = $this->testConnection($drivers, $config);
            }
        }
        return $driver;
    }

    public function index() {
        $data['title'] = 'Subscribe ChatBot';
        $data['package_id'] = '1';
        $data['platforms'] = $this->subscribeFormMod->loadPlatforms();
        $data['websiteTypes'] = $this->subscribeFormMod->loadWebsiteTypes();

        $this->load->view('header', $data);
        $this->load->view('subscribeForm', $data);
        $this->load->view('footer');
    }
    
    public function submitSubscription()
    {
        $config = array(
            "hostname" => $this->input->post('server'),
            "username" => $this->input->post('username'),
            "password" => $this->input->post('password'),
            "database" => $this->input->post('DB_name'),
            "dbdriver" => "",
            "db_debug" => false
        );
        $db_driver = $this->testConnection($this->db_drivers, $config);
        
        if($db_driver == ''){
            $this->session->set_flashdata('db_connection', 'failed');
            redirect(base_url('subscribe'));
        }
        
        $data_client = array(
            'name' => $this->input->post('name'),
            'email'  => $this->input->post('email'),
            'phone'  => $this->input->post('phone')
        );
        
        $insert_id = $this->subscribeFormMod->addClient($data_client);

        $data_company= array(
            'client_id' => $insert_id,
            'name'  => $this->input->post('company'),
            'db_server'  => $this->input->post('server'),
            'db_name'  => $this->input->post('DB_name'),
            'db_username'  => $this->input->post('username'),
            'db_password'  => $this->input->post('password'),
            'db_driver'  => $db_driver,
            'platform_id'  => $this->input->post('platform'),
            'domain'  => $this->input->post('domain'),
            'type_id'  => $this->input->post('website_type'),
            'status'  => 'pending'
        );
        
         $this->subscribeFormMod->addCompany($data_company);
        
        $data_subscriptions= array(
            'client_id' => $insert_id,
            'package_id'  => $this->input->post('package_id'),
            'from_date'  => date("m/d/Y", time()),
            'payment_id'  => '',
            'payment_status'  => 'pending',
            'status'  => 'pending'
        ); 
    
        $insert_id =$this->subscribeFormMod->addSubscription($data_subscriptions);

        $url = 'https://ap-gateway.mastercard.com/api/rest/version/51/merchant/TEST222204466001/session';
        $data = array("apiOperation" => "CREATE_CHECKOUT_SESSION");
        $order = array("currency" => "USD" , "id"=>$insert_id , "amount"=>50);
        $data['order'] = $order;
        $username = "merchant.TEST222204466001";
        $password = "cf51268482346a6c0131fb41e9675bee";
        
        $ch=curl_init($url);
        $data_string = (json_encode($data));
        
        
        curl_setopt($ch, CURLOPT_USERPWD, $username . ":" . $password);  
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);

        $result = curl_exec($ch);
        curl_close($ch);

        
        $result=json_decode($result, true);
        if(isset($result['session']['id']))
        {
            $session_id=$result['session']['id'];
            $indicator=$result['successIndicator'];
            $data_payment= array(
                'indicator' => $indicator,
                'subscription_id'  => $insert_id
            ); 
        
            $this->subscribeFormMod->addPayment($data_payment);
            $data['session_id']=$session_id;
            $this->load->view('paymentGetaway', $data);
        }
    }
    
    public function validateDomain() {
        $url_http = 'http://'. $_POST['domain'].'/';
        $url_https= 'https://'. $_POST['domain'].'/';
        $ch = curl_init($url_http);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $data = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch); 
        if($httpcode>=200 && $httpcode<300){
            echo 'yes';
        } else {
            $ch = curl_init($url_https);
            curl_setopt($ch, CURLOPT_TIMEOUT, 5);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $data = curl_exec($ch);
            $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch); 
            if($httpcode>=200 && $httpcode<300){
                echo 'yes';
            }else{
                echo 'no';
            }
        }
    }

    public function successOrder()
    {
        $valid=false;
        if(isset($_GET['resultIndicator']))
        {
            $indicator = $_GET['resultIndicator'];
            $subscription_id=$this->subscribeFormMod->getSubscription($indicator);
            if($subscription_id !='')
            {
                $url = 'https://ap-gateway.mastercard.com/api/rest/version/50/merchant/TEST222204466001/order/'.$subscription_id;
                
                $username = "merchant.TEST222204466001";
                $password = "cf51268482346a6c0131fb41e9675bee";
                
                $ch=curl_init($url);
                
                
                curl_setopt($ch, CURLOPT_USERPWD, $username . ":" . $password);  
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "GET");
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);

                $result = curl_exec($ch);
                curl_close($ch);

                
                $result=json_decode($result, true);
                if(isset($result['totalAuthorizedAmount']))
                {
                    $message= $result['result'];
                    $status= $result['status'];
                    $totalAuthorizedAmount= $result['totalAuthorizedAmount'];
                    $totalCapturedAmount= $result['totalCapturedAmount'];
                    if ($message == 'SUCCESS' && $status=="CAPTURED" && $totalAuthorizedAmount==$totalCapturedAmount)
                    {
                        $valid=true;
                        
                    }
                    else
                    {
                        $valid=false;

                    }
                }
                else
                {
                    $valid=false;

                }
                if($valid)
                {
                    $this->session->set_flashdata('payment_status', 'success');
                        
                    $subscripe_data = array(
                        'payment_status' => 'success'
                        
                    );
                
                    $this->subscribeFormMod->UpdateSubscripe($subscripe_data,$subscription_id);
                }
                else
                {
                    $this->session->set_flashdata('payment_status', 'failed');

                    $subscripe_data = array(
                        'payment_status' => 'failed'
                    );
                
                    $this->subscribeFormMod->UpdateSubscripe($subscripe_data,$subscription_id);

                }
            }
        }
        redirect(base_url());
    }

}

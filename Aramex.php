<?php

namespace Almajed\Customization\Helper;


use Magento\Framework\View\Result\PageFactory;

class Aramex extends \Magento\Framework\App\Helper\AbstractHelper
{
    const SHIPPING_METHOD = "aramex_aramex";


    /**
     * Object of \Magento\Framework\App\Config\ScopeConfigInterface
     * @var \Magento\Framework\App\Config\ScopeConfigInterface
     */
    protected $scopeConfig;

    /**
     * Object of \Magento\Shipping\Controller\Adminhtml\Order\ShipmentLoader
     * @var \Magento\Shipping\Controller\Adminhtml\Order\ShipmentLoader
     */
    protected $shipmentLoader;

    /**
     * Object of \Magento\Framework\Mail\Template\TransportBuilder
     * @var \Magento\Framework\Mail\Template\TransportBuilder
     */
    protected $transportBuilder;

    /**
     * Object of \Magento\Framework\Controller\Result\JsonFactory
     * @var \Magento\Framework\Controller\Result\JsonFactory
     */
    protected $resultJsonFactory;

    /**
     * Object of \Magento\Framework\DB\Transaction
     * @var \Magento\Framework\DB\Transaction
     */
    protected $transaction;
    /**
     * Object of \Aramex\Shipping\Helper\Data
     * @var \Aramex\Shipping\Helper\Data
     */
    protected $helper;
    /**
     * Object of \Magento\Sales\Model\Order
     * @var \Magento\Sales\Model\Order
     */
    protected $order;
    /**
     * @var \Magento\Framework\Webapi\Soap\ClientFactory
     */
    protected $soapClientFactory;
    /**
     * @var \Magento\Sales\Model\Order\Shipment\Track
     */
    protected $tracking;
    /**
     * @var \Magento\Framework\Registry
     */
    protected $registry;
    protected $orderInterface;

    /**
     * Object of \Magento\Sales\Api\OrderRepositoryInterface
     * @var \Magento\Sales\Api\OrderRepositoryInterface
     */
    protected $orderRepository;

    /**
     * Object of \Magento\Sales\Model\Order\Shipment
     * @var \Magento\Sales\Model\Order\Shipment
     */

    private $shipment;

    private \Magento\Framework\Encryption\EncryptorInterface $encryptor;
    private $_encryptor;
    private $_curl;
    private $timezone;
    private $datetime;
    private $file;
    private $dir;
    private $_fileDriver;
    private $_storeManager;
    private $_orderStatusHelper;
    private int $_orderWebsiteId;
    private int $storeId;

    /**
     * Constructor
     * @param \Magento\Backend\App\Action\Context $context
     * @param PageFactory resultPageFactory
     * @param \Magento\Shipping\Controller\Adminhtml\Order\ShipmentLoader $shipmentLoader
     * @param \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
     * @param \Magento\Framework\Mail\Template\TransportBuilder $transportBuilder
     * @param \Magento\Framework\Controller\Result\JsonFactory $resultJsonFactory
     * @param \Aramex\Shipping\Helper\Data $helper
     * @param \Magento\Sales\Model\Order $order
     * @param \Magento\Sales\Model\Order\Shipment\Track
     * @param \Magento\Framework\DB\Transaction $transaction
     * @param \Magento\Framework\Registry
     */
    public function __construct(
        \Magento\Framework\App\Helper\Context                       $context,
        \Magento\Shipping\Controller\Adminhtml\Order\ShipmentLoader $shipmentLoader,
        \Magento\Framework\App\Config\ScopeConfigInterface          $scopeConfig,
        \Magento\Framework\Mail\Template\TransportBuilder           $transportBuilder,
        \Magento\Framework\Controller\Result\JsonFactory            $resultJsonFactory,
        \Aramex\Shipping\Helper\Data                                $helper,
        \Magento\Sales\Model\Order                                  $order,
        \Magento\Framework\Webapi\Soap\ClientFactory                $soapClientFactory,
        \Magento\Sales\Model\Order\Shipment\Track                   $tracking,
        \Magento\Framework\DB\Transaction                           $transaction,
        \Magento\Sales\Api\Data\OrderInterface                      $orderInterface,
        \Magento\Sales\Api\OrderRepositoryInterface                 $orderRepository,
        \Magento\Framework\Registry                                 $registry,
        \Magento\Framework\Encryption\EncryptorInterface            $encryptor,
        \Magento\Framework\HTTP\Client\Curl                         $curl,
        \Magento\Framework\Stdlib\DateTime\TimezoneInterface        $timezone,
        \Magento\Framework\Stdlib\DateTime\DateTime                 $datetime,
        \Magento\Framework\Filesystem\Io\File                       $file,
        \Magento\Framework\Filesystem\Driver\File                   $fileDriver,
        \Magento\Framework\App\Filesystem\DirectoryList             $dir,
        \Magento\Store\Model\StoreManagerInterface                  $storeManager,
        \Embitel\Shipping\Helper\OrderStatus                        $orderStatusHelper,
        \Magento\Sales\Model\Order\Shipment                         $shipment
    )
    {
        parent::__construct($context);
        $this->scopeConfig = $scopeConfig;
        $this->shipmentLoader = $shipmentLoader;
        $this->transportBuilder = $transportBuilder;
        $this->resultJsonFactory = $resultJsonFactory;
        $this->helper = $helper;
        $this->order = $order;
        $this->transaction = $transaction;
        $this->tracking = $tracking;
        $this->soapClientFactory = $soapClientFactory;
        $this->registry = $registry;
        $this->orderInterface = $orderInterface;
        $this->orderRepository = $orderRepository;
        $this->_encryptor = $encryptor;
        $this->_curl = $curl;
        $this->timezone = $timezone;
        $this->datetime = $datetime;
        $this->file = $file;
        $this->dir = $dir;
        $this->_fileDriver = $fileDriver;
        $this->_storeManager = $storeManager;
        $this->_orderStatusHelper = $orderStatusHelper;
        $this->shipment = $shipment;
    }

    /**
     * {@inheritdoc}
     */
    public function createOrder($order)
    {

        $storeId = $order->getStoreId();
        $websiteId = $this->_storeManager->getStore($storeId)->getWebsiteId();
        $this->_orderWebsiteId = $websiteId;

        $post = [];
        $post['aramex_shipment_shipper_country'] = $this->scopeConfig->getValue(
            'aramex/settings/account_country_code',
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
            $storeId
        );

        $history = [];
        if ($order->getSize()) {
            foreach ($order->getShipmentsCollection() as $_shipment) {
                if ($_shipment->getSize()) {
                    foreach ($_shipment->getCommentsCollection() as $_comment) {
                        $history[] = $_comment->getComment();
                    }
                }
            }
        }
        if (!empty($history)) {
            foreach ($history as $_history) {
                $awbno = strstr($_history, "- Order No", true);
            }
        }
        $orderItem['method'] = 'EXP';
        if (!isset($awbno)) {
            $shipping = $order->getShippingAddress();
            $shippingCountry = ($shipping) ? $shipping->getData('country_id') : '';
            if ($shippingCountry == $post['aramex_shipment_shipper_country']) {
                $orderItem['method'] = "DOM";
            } else {
                $orderItem['method'] = "EXP";
            }
        }


        $responce = "";

        //   $post['aramex_shipment_original_reference'] = (int) $order->getIncrementId();
        $post['aramex_shipment_original_reference'] = $order->getIncrementId();
        // $order = $this->order->loadByIncrementId($order->getIncrementId());
        $isShipped = false;
        $itemsv = $order->getAllVisibleItems();
        $totalWeight = 0;
        foreach ($itemsv as $itemvv) {
            $weight = $this->getTotalWeight($itemvv);
            $totalWeight += $weight;
            if ($itemvv->getQtyOrdered() == $itemvv->getQtyShipped()) {
                $isShipped = true;
            }
            //quontity
            $_qty = abs($itemvv->getQtyOrdered() - $itemvv->getQtyShipped());
            if ($_qty == 0 and $isShipped) {
                $_qty = (int)$itemvv->getQtyShipped();
            }

            $post[$itemvv->getId()] = (string)$_qty;
        }

        $post['aramex_items'] = $this->getTotalItems($itemsv, $isShipped);
        $post['order_weight'] = (string)$totalWeight;
        $post['aramex_shipment_shipper_reference'] = $order->getIncrementId();
        $post['aramex_shipment_info_billing_account'] = 1;
        $post['aramex_shipment_shipper_account'] = $this->scopeConfig->
        getValue(
            'aramex/settings/account_number',
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
            $storeId
        );
        $post['aramex_shipment_shipper_street'] = $this->scopeConfig->getValue(
            'aramex/shipperdetail/address',
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
            $storeId
        );
        $post['aramex_shipment_shipper_city'] = $this->scopeConfig->getValue(
            'aramex/shipperdetail/city',
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
            $storeId
        );
        $post['aramex_shipment_shipper_state'] = $this->scopeConfig->getValue(
            'aramex/shipperdetail/state',
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
            $storeId
        );
        $post['aramex_shipment_shipper_postal'] = $this->scopeConfig->
        getValue(
            'aramex/shipperdetail/postalcode',
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
            $storeId
        );
        $post['aramex_shipment_shipper_name'] = $this->scopeConfig->getValue(
            'aramex/shipperdetail/name',
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
            $storeId
        );
        $post['aramex_shipment_shipper_company'] = $this->scopeConfig->getValue(
            'aramex/shipperdetail/company',
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        );
        $post['aramex_shipment_shipper_phone'] = $this->scopeConfig->getValue(
            'aramex/shipperdetail/phone',
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
            $storeId
        );
        $post['aramex_shipment_shipper_email'] = $this->scopeConfig->getValue(
            'aramex/shipperdetail/email',
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
            $storeId
        );

        //shipper parameters
        $post['aramex_shipment_receiver_reference'] = $order->getIncrementId();
        $shipping = $order->getShippingAddress();
        $billing = $order->getBillingAddress();
        $postcode = '12345';
        $street = $shipping->getData('street');
        $region = $shipping->getData('region');
        $city = $shipping->getData('city');


        if ($shipping && (strlen($shipping->getData('postcode') > 2))) {
            $postcode = $shipping->getData('postcode');
        }

        $post['aramex_shipment_receiver_street'] = (strlen(mb_strlen($street, 'UTF-8') > 2) || strlen($city, 'UTF-8') > 2) ? $city .", ". $street : "no info.";
        $post['aramex_shipment_receiver_city'] = (!empty($region)) ? $region : "no region";
        $post['aramex_shipment_receiver_state'] = (!empty($city)) ? $city : "no city";
        $post['aramex_shipment_receiver_postal'] = $postcode;
        $post['aramex_shipment_receiver_country'] = ($shipping) ? $shipping->getData('country_id') : '';
        $post['aramex_shipment_receiver_name'] = ($shipping) ? $shipping->getName() : '';

        //Contact Info
        $post['aramex_shipment_receiver_name'] = ($shipping) ? $shipping->getName() : '';
        $company_name = isset($billing) ? $billing->getData('company') : '';
        $company_name = ($company_name) ? $company_name : '';
        $company_name = (empty($company_name) and $shipping) ? $shipping->getName() : $company_name;
        $company_name = ($shipping) ? $shipping->getData('company') : '';

        $post['aramex_shipment_receiver_company'] = (!empty($company_name)) ? $company_name : $post['aramex_shipment_receiver_name'];
        $post['aramex_shipment_receiver_phone'] = ($shipping) ? $shipping->getData('telephone') : '';
        $post['aramex_shipment_receiver_email'] = $order->getData('customer_email');
        // Other Main Shipment Parameters
        $post['aramex_shipment_info_reference'] = $order->getId();
        $post['aramex_shipment_info_foreignhawb'] = '';
        $post['aramex_shipment_info_comment'] = (!empty($shipping->getQatarNationalId())) ? $shipping->getQatarNationalId() : '';
        $post['weight_unit'] = 'KG';

        $payment = $order->getPayment();
        $method = $payment->getMethodInstance();
        $methodTitle = $method->getTitle();


        $paymentType = "P";
        $paymentOption = "PPST";
        $serviceCodes = "";
        if ($methodTitle == "الدفع عند الاستلام" || $methodTitle == "Cash On Delivery") {
            $paymentType = "P";
            $paymentOption = "PPST";
            $serviceCodes = "CODS";
            //  $this->addLog($methodTitle);
        }
        if ($orderItem['method'] == 'EXP') {

            $post['aramex_shipment_info_product_group'] = $orderItem['method'];
            $post['aramex_shipment_info_product_type'] = "DGX";
            $post['aramex_shipment_info_payment_type'] = $paymentType;
            $post['aramex_shipment_info_payment_option'] = $paymentOption;
            $post['aramex_shipment_info_service_type'] = "";
            $post['aramex_shipment_currency_code'] = $order->getBaseCurrencyCode();
            $post['aramex_shipment_info_custom_amount'] = round($order->getData('grand_total'), 2);
        }
        if ($orderItem['method'] == 'DOM') {

            $post['aramex_shipment_info_product_group'] = $orderItem['method'];
            $post['aramex_shipment_info_product_type'] = "CDS";
            $post['aramex_shipment_info_payment_type'] = $paymentType;
            $post['aramex_shipment_info_payment_option'] = $paymentOption;
            $post['aramex_shipment_info_service_type'] = $serviceCodes;
            $post['aramex_shipment_currency_code'] = $order->getBaseCurrencyCode();
            $post['aramex_shipment_info_custom_amount'] = round($order->getData('grand_total'), 2);
        }

        $aramex_shipment_description = $this->getShipmentDescription($order);
        $post['aramex_shipment_description'] = $aramex_shipment_description;
        $post['aramex_shipment_info_cod_amount'] = 0;
        $orderPaymentStatus = false;
        if ($methodTitle == "الدفع عند الاستلام" || $methodTitle == "Cash On Delivery") {
            $orderPaymentStatus = true;
        }
        if($orderPaymentStatus) {
            $post['aramex_shipment_info_cod_amount'] = $order->getData('grand_total');
        }
        $post['aramex_return_shipment_creation_date'] = "create";
        $post['aramex_shipment_referer'] = 0;


        $replay = $this->postAction($orderItem['method'], $post);
        if ($replay[1] == "DOM") {
            $method = "Domestic Product Group";
        } else {
            $method = "International Product Group";
        }

        if ($replay[2] == "error") {
            $responce .= "<p class='aramex_red'>" . $replay[0] . " - " .
                $order->getIncrementId() . ' not created. (' . $method . ')</p>';
        } else {
            $responce .= "<p class='aramex_green'> Aramex Shipment Number: " .
                $order->getIncrementId() . ' has been created.(' . $method . ')</p>';
        }

        return $responce;

    }

    /**
     * Makes request to "Aramex shipment" API
     *
     * @param string $method Shipping method
     * @param array $post "Post" request
     * @return array Information from server
     */
    private function postAction($method, $post = [])
    {

        $baseUrl = $this->helper->getWsdlPath();
        //SOAP object
        $soapClient = $this->soapClientFactory->create($baseUrl . 'shipping.wsdl', ['version' => SOAP_1_1, 'trace' => 1, 'keep_alive' => false]);
        $errors = [];
        try {
            /* here's your form processing */
            $order_id = $post['aramex_shipment_shipper_reference'];
            // $order = $this->order->load($order_id);
            $order = $this->order->loadByIncrementId($order_id);
            $major_par = $this->getParameters($order, $post);
            // $this->addLog( $major_par);
            try {
                //create shipment call

                $auth_call = $soapClient->CreateShipments($major_par);
                if ($auth_call->HasErrors) {
                    //    $this->addLog( $major_par);
                    $errors = $this->getErrorsText($auth_call);
                    //   $this->addLog( $errors);
                    return ([$errors, $method, 'error']);
                } else {
                    $data = [
                        'items' => $post['aramex_items'],
                        'comment_text' => "Aramex Shipment Order AWB No. 3" . $auth_call->Shipments->ProcessedShipment->
                            ID . " - Order No. " . $order->getId() .
                            " <a style='color:red;'>Print Label #" . $auth_call->Shipments->ProcessedShipment->ID . "</a>",
                        'comment_customer_notify' => false,
                        'is_visible_on_front' => true
                    ];

                    if ($order->canShip() && $post['aramex_return_shipment_creation_date'] == "create") {
                        $this->shipmentLoader->setOrderId($order->getId());
                        $this->shipmentLoader->setShipmentId(null);
                        $this->shipmentLoader->setShipment($data);
                        $this->shipmentLoader->setTracking(null);
                        $shipment = $this->shipmentLoader->load();

                        if ($shipment) {
                            $track = $this->tracking->setNumber($auth_call->Shipments->ProcessedShipment->ID)
                                ->setCarrierCode("aramex")->setTitle("Aramex Global Shipping");
                            $shipment->addTrack($track);
                        }
                        if (!empty($data['comment_text'])) {
                            $shipment->addComment(
                                $data['comment_text'],
                                isset($data['comment_customer_notify']),
                                isset($data['is_visible_on_front'])
                            );

                            $shipment->setCustomerNote($data['comment_text']);
                            $shipment->setCustomerNoteNotify(isset($data['comment_customer_notify']));
                        }

                        $shipment->register();
                        $this->_saveShipment($shipment);
                        //  $this->addLog("shipment created");
                        $this->generateAwb($order, $auth_call->Shipments->ProcessedShipment->ID);
                        //  $this->addLog("AWB Generated");
                        $this->generateSchedulePickup($order);
                        //    $this->addLog("Schedule created");
                        /* sending mail */
                        // $this->sendEmail($order, $auth_call);

                        return true;
                    } else {
                        $this->addLog('Cannot do shipment for the order.');
                        return false;
                    }

                }
            } catch (\Exception $e) {
                $errors = $e->getMessage();
                $this->addLog($errors);
                return false;
            }
        } catch (\Exception $e) {
            $errors = $e->getMessage();
            $this->addLog($errors);
            return false;
        }
    }

    /**
     * Saves Aramex shipment
     *
     * @param object $shipment Shipment object
     * @return void
     */
    private function _saveShipment($shipment)
    {

        $shipment->getOrder()->setIsInProcess(true);
        $this->transaction->addObject(
            $shipment
        )->addObject(
            $shipment->getOrder()
        )->save();
        $this->registry->unregister('current_shipment');
    }


    /**
     * Gets quantity of ordered products in order
     *
     * @param array $itemsv Orders
     * @return array List of products
     */
    private function getTotalItems($itemsv, $isShipped)
    {
        $post = [];
        foreach ($itemsv as $item) {
            if ($item->getQtyOrdered() > $item->getQtyShipped() or $isShipped) {
                $_qty = abs($item->getQtyOrdered() - $item->getQtyShipped());
                if ($_qty == 0 && $isShipped) {
                    $_qty = (int)$item->getQtyShipped();
                }
                $post[$item->getId()] = $_qty;
            }
        }
        return $post;
    }

    /**
     * Gets total weight of order
     *
     * @param array $itemvv Orders
     * @return string Weight
     */
    private function getTotalWeight($itemvv)
    {
        if ($itemvv->getWeight() != 0) {
            $weight = $itemvv->getWeight() * $itemvv->getQtyOrdered();
        } else {
            $weight = 0.5 * $itemvv->getQtyOrdered();
        }
        return $weight;
    }

    /**
     * Gets description of shipment
     *
     * @param object $order Order
     * @return string Description of order
     */
    private function getShipmentDescription($order)
    {
        $aramex_shipment_description = '';
        var_dump($order->getAllVisibleItems());die();
        foreach ($order->getAllVisibleItems() as $itemname) {
            if ($itemname->getQtyOrdered() > $itemname->getQtyShipped()) {
                $aramex_shipment_description = $aramex_shipment_description . $itemname->getId() . ' - ' .
                    trim($itemname->getName() ?? '');
            }
        }
        return $aramex_shipment_description;
    }

    /**
     * Gets errors description
     *
     * @param object $auth_call Feadbeck from Aramex server
     * @return string Errors description
     */
    private function getErrorsText($auth_call)
    {
        if (empty($auth_call->Shipments)) {
            if (count($auth_call->Notifications->Notification) > 1) {
                foreach ($auth_call->Notifications->Notification as $notify_error) {
                    $errors = 'Aramex: ' . $notify_error->Code . ' - ' . $notify_error->Message;
                }
            } else {
                $errors = 'Aramex: ' . $auth_call->Notifications->Notification->Code . ' - ' .
                    $auth_call->Notifications->Notification->Message;
            }
        } else {
            if (is_array($auth_call->Shipments->ProcessedShipment->Notifications->Notification)) {
                $notification_string = '';
                foreach ($auth_call->Shipments->ProcessedShipment->Notifications->Notification as
                         $notification_error) {
                    $notification_string .= $notification_error->Code . ' - ' .
                        $notification_error->Message . ' <br />';
                }
                $errors = $notification_string;
            } else {
                $errors = 'Aramex: ' . $auth_call->Shipments->ProcessedShipment->Notifications->
                    Notification->Code . ' - ' . $auth_call->Shipments->ProcessedShipment->
                    Notifications->Notification->Message;
            }
        }
        return $errors;
    }


    /**
     * Creates array with parameters for request to Aramex server
     *
     * @param object $order Order
     * @param array $post Post request
     * @return array Array with parameters for request to Aramex server
     */
    private function getParameters($order, $post)
    {
        $totalItems = 0;
        $items = $order->getAllItems();
        $descriptionOfGoods = '';
        foreach ($order->getAllVisibleItems() as $itemname) {
            $descriptionOfGoods .= $itemname->getId() . ' - ' . trim($itemname->getName() ?? '');
        }
        $aramex_items_counter = 0;
        $aramex_items = array();

        foreach ($post['aramex_items'] as $key => $value) {
            $aramex_items_counter++;
            if ($value > 0) {
                //itrating order items
                foreach ($items as $item) {
                    if ($item->getId() == $key) {
                        //get weight
                        if ($item->getWeight() != 0) {
                            $weight = $item->getWeight() * $item->getQtyOrdered();
                        } else {
                            $weight = 0.5 * $item->getQtyOrdered();
                        }
                        // collect items for aramex
                        $aramex_items[] = [
                            'PackageType' => 'Box',
                            'Quantity' => $post[$item->getId()],
                            'Weight' => [
                                'Value' => $weight,
                                'Unit' => 'Kg'
                            ],
                            'Comments' => $item->getName(),
                            'Reference' => ''
                        ];
                        $totalItems = $totalItems + $post[$item->getId()];
                    }
                }
            }
        }

        $totalWeight = $post['order_weight'];
        $params = [];
        //shipper parameters
        $params['Shipper'] = [
            'Reference1' => $post['aramex_shipment_shipper_reference'],
            'Reference2' => '',
            'AccountNumber' => ($post['aramex_shipment_info_billing_account'] == 1) ?
                $post['aramex_shipment_shipper_account'] : $post['aramex_shipment_shipper_account'],
            //Party Address
            'PartyAddress' => [
                'Line1' => $post['aramex_shipment_shipper_street'],
                'Line2' => '',
                'Line3' => '',
                'City' => $post['aramex_shipment_shipper_city'],
                'StateOrProvinceCode' => $post['aramex_shipment_shipper_state'],
                'PostCode' => $post['aramex_shipment_shipper_postal'],
                'CountryCode' => $post['aramex_shipment_shipper_country'],
            ],
            //Contact Info
            'Contact' => [
                'Department' => '',
                'PersonName' => $post['aramex_shipment_shipper_name'],
                'Title' => '',
                'CompanyName' => $post['aramex_shipment_shipper_company'],
                'PhoneNumber1' => $post['aramex_shipment_shipper_phone'],
                'PhoneNumber1Ext' => '',
                'PhoneNumber2' => '',
                'PhoneNumber2Ext' => '',
                'FaxNumber' => '',
                'CellPhone' => $post['aramex_shipment_shipper_phone'],
                'EmailAddress' => $post['aramex_shipment_shipper_email'],
                'Type' => ''
            ],
        ];
        //consinee parameters
        $params['Consignee'] = [
            'Reference1' => $post['aramex_shipment_receiver_reference'],
            'Reference2' => '',
            'AccountNumber' => ($post['aramex_shipment_info_billing_account'] == 2) ?
                $post['aramex_shipment_shipper_account'] : '',
            //Party Address
            'PartyAddress' => [
                'Line1' => $post['aramex_shipment_receiver_street'],
                'Line2' => '',
                'Line3' => '',
                'City' => $post['aramex_shipment_receiver_city'],
                'StateOrProvinceCode' => $post['aramex_shipment_receiver_state'],
                'PostCode' => $post['aramex_shipment_receiver_postal'],
                'CountryCode' => $post['aramex_shipment_receiver_country'],
            ],
            //Contact Info
            'Contact' => [
                'Department' => '',
                'PersonName' => $post['aramex_shipment_receiver_name'],
                'Title' => '',
                'CompanyName' => $post['aramex_shipment_receiver_company'],
                'PhoneNumber1' => $post['aramex_shipment_receiver_phone'],
                'PhoneNumber1Ext' => '',
                'PhoneNumber2' => '',
                'PhoneNumber2Ext' => '',
                'FaxNumber' => '',
                'CellPhone' => $post['aramex_shipment_receiver_phone'],
                'EmailAddress' => $post['aramex_shipment_receiver_email'],
                'Type' => ''
            ]
        ];

        // Other Main Shipment Parameters
        $params['Reference1'] = $post['aramex_shipment_info_reference'];
        $params['Reference2'] = '';
        $params['Reference3'] = '';
        $params['ForeignHAWB'] = $post['aramex_shipment_info_foreignhawb'];

        $params['TransportType'] = 0;
        $params['ShippingDateTime'] = time();
        $params['DueDate'] = time() + (7 * 24 * 60 * 60);
        $params['PickupLocation'] = 'Reception';
        $params['PickupGUID'] = '';
        $params['Comments'] = $post['aramex_shipment_info_comment'];
        $params['AccountingInstrcutions'] = '';
        $params['OperationsInstructions'] = '';
        $params['Details'] = [
            'Dimensions' => [
                'Length' => '0',
                'Width' => '0',
                'Height' => '0',
                'Unit' => 'cm'
            ],
            'ActualWeight' => [
                'Value' => $totalWeight,
                'Unit' => $post['weight_unit']
            ],
            'ProductGroup' => $post['aramex_shipment_info_product_group'],
            'ProductType' => $post['aramex_shipment_info_product_type'],
            'PaymentType' => $post['aramex_shipment_info_payment_type'],
            'PaymentOptions' => $post['aramex_shipment_info_payment_option'],
            'Services' => $post['aramex_shipment_info_service_type'],
            'NumberOfPieces' => $totalItems,
            'DescriptionOfGoods' => (trim($post['aramex_shipment_description'] ?? '') == '') ?
                $descriptionOfGoods : $post['aramex_shipment_description'],
            'GoodsOriginCountry' => $post['aramex_shipment_shipper_country'],
            'Items' => $aramex_items,
        ];

        $params['Details']['CashOnDeliveryAmount'] = [
            'Value' => $post['aramex_shipment_info_cod_amount'],
            'CurrencyCode' => $post['aramex_shipment_currency_code']
        ];

        $params['Details']['CustomsValueAmount'] = [
            'Value' => $post['aramex_shipment_info_custom_amount'],
            'CurrencyCode' => $post['aramex_shipment_currency_code']
        ];

        $major_par['Shipments'][] = $params;
        $clientInfo = $this->getClientInfo($order->getId());
        $major_par['ClientInfo'] = $clientInfo;
        $major_par['LabelInfo'] = [
            'ReportID' => 9729,
            'ReportType' => 'URL'
        ];
        return $major_par;
    }


    /**
     * Transforms string to array
     *
     * @param string $str String for transformation
     * @return array Transformed string to array
     */
    private function unserializeForm($str)
    {
        $returndata = [];
        $strArray = explode("&", $str ?? '');
        foreach ($strArray as $item) {
            $array = explode("=", $item ?? '');
            $returndata[$array[0]] = $array[1];
        }
        return $returndata;
    }

    public function generateAwb($order, $label_id)
    {
        $_order = $this->orderRepository->get($order->getId());
        $baseUrl = $this->helper->getWsdlPath();
        $soapClient = $this->soapClientFactory->create($baseUrl .
            'shipping.wsdl', ['version' => SOAP_1_1, 'trace' => 1, 'keep_alive' => false]);
        $clientInfo = $this->getClientInfo($order->getId());

        if (!is_object($_order->getSize())) {
            $report_id = 9729;
            $shipmentNumber = $label_id;
            $params = [
                'ClientInfo' => $clientInfo,
                'Transaction' => [
                    'Reference1' => $order->getIncrementId(),
                    'Reference2' => '',
                    'Reference3' => '',
                    'Reference4' => '',
                    'Reference5' => '',
                ],
                'LabelInfo' => [
                    'ReportID' => $report_id,
                    'ReportType' => 'URL',
                ],
            ];
            $params['ShipmentNumber'] = $shipmentNumber;
            try {
                $auth_call = $soapClient->PrintLabel($params);
                /* bof  PDF demaged Fixes debug */
                if ($auth_call->HasErrors) {
                    if (!is_object($auth_call->Notifications->Notification)) {
                        foreach ($auth_call->Notifications->Notification as $notify_error) {
                            $error = "";
                            $error .= 'Aramex: ' . $notify_error->Code . ' - ' . $notify_error->Message;
                        }
                        $this->addLog($error);
                    } else {
                        $this->addLog('Aramex: ' . $auth_call->Notifications->Notification->Code .
                            ' - ' . $auth_call->Notifications->Notification->Message);
                    }
                }

                $filepath = $auth_call->ShipmentLabel->LabelURL;
                $trackingList = $_order->getTracksCollection();

                foreach ($trackingList as $tracking) {

                    $createdDate = $tracking->getCreatedAt();
                    $shippmentDir = $this->dir->getPath('pub') . '/shipping/';
                    $year = date("Y", strtotime($createdDate));
                    $month = date("m", strtotime($createdDate));
                    $day = date("d", strtotime($createdDate));

                    if (!$this->_fileDriver->isExists($shippmentDir)) {
                        $this->file->mkdir($shippmentDir);
                    }

                    $shippmentDir = $shippmentDir . $year . "/";
                    if (!$this->_fileDriver->isExists($shippmentDir)) {
                        $this->file->mkdir($shippmentDir);
                    }

                    $shippmentDir = $shippmentDir . $month . "/";
                    if (!$this->_fileDriver->isExists($shippmentDir)) {
                        $this->file->mkdir($shippmentDir);
                    }

                    $shippmentDir = $shippmentDir . $day . "/";
                    if (!$this->_fileDriver->isExists($shippmentDir)) {
                        $this->file->mkdir($shippmentDir);
                    }

                    $this->addLog("AWB_LABEL URL =>" . $filepath);
                    $invoiceId = $this->getOrderInvoiceId($order);
                    $pdfName = $invoiceId . "_" . $tracking->getTrackNumber() . ".pdf";
                    $fileName = $shippmentDir . $pdfName;
                    $this->_fileDriver->filePutContents($fileName, file_get_contents($filepath));
                }


            } catch (\SoapFault $fault) {
                $this->addLog('Error : ' . $fault->faultstring);

            } catch (\Exception $e) {
                $this->addLog($e->getMessage());
            }
        } else {
            $this->addLog('Shipment is empty or not created yet.');
        }
    }

    protected function getOrderInvoiceId($order)
    {
        $invoiceId = "";
        $invoiceId = $order->getIncrementId();
        if ($order->hasInvoices()) {
            $invoiceCollection = $order->getInvoiceCollection();
            $orderInvoice = $invoiceCollection->getFirstItem();
            $invoiceId = $orderInvoice->getIncrementId();
            $invoiceId = $order->getIncrementId();
        }
        return $invoiceId;
    }


    public function addLog($logdata)
    {
        $writer = new \Zend_Log_Writer_Stream(BP . '/var/log/aramex.log');
        $logger = new \Zend_Log();
        $logger->addWriter($writer);
        $logger->info($logdata);
    }

    /**
     * {@inheritdoc}
     */
    public function generateSchedulePickup($order)
    {

        $response = [];
        $clientInfo = $this->getClientInfo($order->getId());
        $storeId = $order->getStoreId();
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $scopeConfig = $objectManager->create('\Magento\Framework\App\Config\ScopeConfigInterface');
        $country = $scopeConfig->getValue('aramex/shipperdetail/country', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, $storeId);
        $phone = $scopeConfig->getValue('aramex/shipperdetail/phone', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, $storeId);
        $company = $scopeConfig->getValue('aramex/shipperdetail/company', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, $storeId);
        $city = $scopeConfig->getValue('aramex/shipperdetail/city', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, $storeId);
        $postalcode = $scopeConfig->getValue('aramex/shipperdetail/postalcode', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, $storeId);
        $address = $scopeConfig->getValue('aramex/shipperdetail/address', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, $storeId);
        $contact = $scopeConfig->getValue('aramex/shipperdetail/name', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, $storeId);
        $email = $scopeConfig->getValue('aramex/shipperdetail/email', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, $storeId);
        $state = $scopeConfig->getValue('aramex/shipperdetail/state', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, $storeId);


        $totalWeight = 0;
        $itemscount = 0;
        $itemsv = $order->getAllVisibleItems();
        foreach ($itemsv as $itemvv) {
            if ($itemvv->getQtyOrdered() > $itemvv->getQtyShipped()) {
                $itemscount += $itemvv->getQtyOrdered() - $itemvv->getQtyShipped();
            } elseif ($itemvv->getQtyOrdered() == $itemvv->getQtyShipped()) {
                $itemscount += $itemvv->getQtyShipped();
            }
            if ($itemvv->getWeight() != 0) {
                $weight = $itemvv->getWeight() * $itemvv->getQtyOrdered();
            } else {
                $weight = 0.5 * $itemvv->getQtyOrdered();
            }
            $totalWeight += $weight;
        }
        try {
            //  $post = $post['pickup'];
            $_order = $this->order->loadByIncrementId($order->getIncrementId());

            $OrderDate = strtotime($order->getCreatedAt());
            $tommorow = '+1 day';
            $pickupDate = strtotime($tommorow, $OrderDate);
            $readyTimeH = 11;
            $readyTimeM = 00;
            $readyTime = mktime(
                ($readyTimeH),
                $readyTimeM,
                0,
                date("m", $pickupDate),
                date("d", $pickupDate),
                date("Y", $pickupDate)
            );

            $closingTimeH = 16;
            $closingTimeM = 00;
            $closingTime = mktime(
                ($closingTimeH),
                $closingTimeM,
                0,
                date("m", $pickupDate),
                date("d", $pickupDate),
                date("Y", $pickupDate)
            );
            $params = [
                'ClientInfo' => $clientInfo,
                'Transaction' => [
                    'Reference1' => $order->getIncrementId()
                ],
                'Pickup' => [
                    'PickupContact' => [
                        'PersonName' => $company,
                        'CompanyName' => $company,
                        'PhoneNumber1' => $phone,
                        'PhoneNumber1Ext' => '',
                        'CellPhone' => $phone,
                        'EmailAddress' => $email
                    ],
                    'PickupAddress' => [
                        'Line1' => $address,
                        'City' => $city,
                        'StateOrProvinceCode' => $state,
                        'PostCode' => $postalcode,
                        'CountryCode' => $country
                    ],
                    'PickupLocation' => "Reception",
                    'PickupDate' => $readyTime,
                    'ReadyTime' => $readyTime,
                    'LastPickupTime' => $closingTime,
                    'ClosingTime' => $closingTime,
                    'Comments' => "",
                    'Reference1' => $order->getIncrementId(),
                    'Reference2' => '',
                    'Vehicle' => "Bike",
                    'Shipments' => [
                        'Shipment' => []
                    ],
                    'PickupItems' => [
                        'PickupItemDetail' => [
                            'ProductGroup' => "EXP",
                            'ProductType' => "EPX",
                            'Payment' => "P",
                            'NumberOfShipments' => 1,
                            'NumberOfPieces' => $itemscount,
                            'ShipmentWeight' => ['Value' => $totalWeight, 'Unit' => 'KG'],
                        ],
                    ],
                    'Status' => "Ready"
                ]
            ];
            $baseUrl = $this->helper->getWsdlPath();
            //SOAP object
            $soapClient = $this->soapClientFactory->create($baseUrl .
                'shipping.wsdl', ['version' => SOAP_1_1, 'trace' => 1, 'keep_alive' => false]);
            try {
                $results = $soapClient->CreatePickup($params);
                //   $this->addLog("pickup: ".print_r($params));
                $error = "";
                if ($results->HasErrors) {
                    if (is_array($results->Notifications->Notification)) {
                        $error = "";
                        foreach ($results->Notifications->Notification as $notify_error) {
                            $error .= 'Aramex: ' . $notify_error->Code . ' - ' . $notify_error->Message . "<br>";
                        }
                        $this->addLog("pickup Error: " . $error);
                        // return $error;
                    } else {
                        $error .= 'Aramex: ' . $results->Notifications->Notification->Code . ' - ' .
                            $results->Notifications->Notification->Message;
                    }
                    $this->addLog("pickup Error: " . $error);
                    //return $error;
                } else {
                    $notify = false;
                    $comment = "Pickup reference number ( <strong>" . $results->ProcessedPickup->ID . "</strong> ).";
                    $history = $_order->addStatusHistoryComment($comment, $_order->getStatus())
                        ->setIsCustomerNotified($notify);
                    $history->save();
                    $shipmentId = null;
                    $shipment = $this->shipment->getCollection()
                        ->addFieldToFilter("order_id", $_order->getId())->load();

                    if ($shipment->getSize() > 0) {
                        foreach ($shipment as $_shipment) {
                            $shipmentId = $_shipment->getId();
                            break;
                        }
                    }
                    if ($shipmentId != null) {
                        $data = [['comment_text' => $comment]];

                        $this->shipmentLoader->setOrderId($_order->getId());
                        $this->shipmentLoader->setShipmentId(null);
                        $this->shipmentLoader->setShipment($data);
                        $this->shipmentLoader->setTracking(null);
                        $shipment = $this->shipmentLoader->load();
                        if (!empty($data['comment_text'])) {
                            $shipment->addComment(
                                $data['comment_text'],
                                isset($data['comment_customer_notify']),
                                isset($data['is_visible_on_front'])
                            );

                            $shipment->setCustomerNote($data['comment_text']);
                            $shipment->setCustomerNoteNotify(isset($data['comment_customer_notify']));
                        }
                    }
                }
            } catch (\Exception $e) {
                $this->addLog("pickup Error: " . $e->getMessage());
            }
        } catch (\Exception $e) {
            $this->addLog("pickup Error: " . $e->getMessage());
        }
    }


    public function getClientInfo($orderId)
    {

        //  $orderId = $this->request->getParam('order_id');

        if ($orderId != null) {
            $_order = $this->orderRepository->get($orderId);
        }

        if ($orderId && isset($_order)) {
            $storeId = (int)$_order->getStoreId();
        } else {
            $storeId = $this->storeId;
        }

        $account = $this->scopeConfig->
        getValue(
            'aramex/settings/account_number',
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
            $storeId
        );
        $username = $this->scopeConfig->
        getValue(
            'aramex/settings/user_name',
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
            $storeId
        );
        $password = $this->scopeConfig->
        getValue(
            'aramex/settings/password',
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
            $storeId
        );
        $pin = $this->scopeConfig->
        getValue(
            'aramex/settings/account_pin',
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
            $storeId
        );
        $entity = $this->scopeConfig->
        getValue(
            'aramex/settings/account_entity',
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
            $storeId
        );
        $country_code = $this->scopeConfig->
        getValue(
            'aramex/settings/account_country_code',
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
            $storeId
        );

        $paymentType = $this->scopeConfig->
        getValue(
            'aramex/config/default_payment_method',
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
            $storeId
        );
        return [
            'AccountCountryCode' => $country_code,
            'AccountEntity' => $entity,
            'AccountNumber' => $account,
            'AccountPin' => $pin,
            'UserName' => $username,
            'Password' => $password,
            'Version' => 'v1.0',
            'Source' => 31,
            'PaymentType' => $paymentType
        ];
    }


}
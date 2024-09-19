<?php 
/**
 * Barcode Buddy for Grocy MQTT Plugin
 *
 * PHP version 7
 *
 * LICENSE: This source file is subject to version 3.0 of the GNU General
 * Public License v3.0 that is attached to this project.
 *
 * 
 * MQTT Plugin
 *
 * @author     Pierre Gorissen
 * @copyright  2022 Pierre Gorissen
 * @license    https://www.gnu.org/licenses/gpl-3.0.en.html  GNU GPL v3.0
 * @since      Plugin available since Release v1.8.1.4 of Barcode Buddy
 */

require_once __DIR__ . "/composer/vendor/autoload.php";
require_once __DIR__ . "/composer/vendor/php-mqtt/client/src/MQTTClient.php";
require_once __DIR__ . "/config.php";
require_once __DIR__ . "/../incl/db.inc.php";


use PhpMqtt\Client\Exceptions\MqttClientException;
use PhpMqtt\Client\MqttClient;


const EVENT_TYPE_PLUGIN               = -2;
const EVENT_TYPES   = array(
    "-1" => "ERROR",
    "0" => "MODE_CHANGE",
    "1" => "CONSUME",
    "2" => "CONSUME_S",
    "3" => "PURCHASE", 
    "4" => "OPEN", 
    "5" => "INVENTORY",
    "6" => "EXEC_CHORE",
    "7" => "ADD_KNOWN_BARCODE",
    "8" => "ADD_NEW_BARCODE",
    "9" => "ADD_UNKNOWN_BARCODE",
    "10" => "CONSUME_PRODUCT", 
    "11" => "CONSUME_S_PRODUCT",
    "12" => "PURCHASE_PRODUCT",
    "13" => "OPEN_PRODUCT",
    "14" => "GET_STOCK_PRODUCT",
    "15" => "ADD_TO_SHOPPINGLIST",
    "16" => "ASSOCIATE_PRODUCT",
    "17" => "ACTION_REQUIRED", 
    "18" => "CONSUME_ALL_PRODUCT",
    "19" => "NO_STOCK"
);






//Event types used for plugins
const EVENT_TYPE_ERROR               = -1;
const EVENT_TYPE_MODE_CHANGE         = 0;
const EVENT_TYPE_CONSUME             = 1;
const EVENT_TYPE_CONSUME_S           = 2;
const EVENT_TYPE_PURCHASE            = 3;
const EVENT_TYPE_OPEN                = 4;
const EVENT_TYPE_INVENTORY           = 5;
const EVENT_TYPE_EXEC_CHORE          = 6;
const EVENT_TYPE_ADD_KNOWN_BARCODE   = 7;
const EVENT_TYPE_ADD_NEW_BARCODE     = 8;
const EVENT_TYPE_ADD_UNKNOWN_BARCODE = 9;
const EVENT_TYPE_CONSUME_PRODUCT     = 10;
const EVENT_TYPE_CONSUME_S_PRODUCT   = 11;
const EVENT_TYPE_PURCHASE_PRODUCT    = 12;
const EVENT_TYPE_OPEN_PRODUCT        = 13;
const EVENT_TYPE_GET_STOCK_PRODUCT   = 14;
const EVENT_TYPE_ADD_TO_SHOPPINGLIST = 15;
const EVENT_TYPE_ASSOCIATE_PRODUCT   = 16;
const EVENT_TYPE_ACTION_REQUIRED     = 17;
const EVENT_TYPE_CONSUME_ALL_PRODUCT = 18;
const EVENT_TYPE_NO_STOCK            = 19;

// Make an enum for all transformed event types
class EventType {
    const ERROR = -1;
    const MODE_CHANGE = 0;
    const RETRO_QUANTITY_UPDATE = 1;
    const EXEC_CHORE = 2;
    const IMPORT_AND_CONSUME = 3;
    const IMPORT_AND_CONSUME_FAIL = 4;
    const IMPORT_AND_PURCHASE = 5;
    const SCAN_UNKNOWN_INC = 6;
    const SCAN_UNKNOWN_FOUND = 7;
    const SCAN_UNKNOWN_NOT_FOUND = 8;
    const CONSUME = 9;
    const CONSUME_SPOILED = 10;
    const PURCHASE = 11;
    const OPEN = 12;
    const INVENTORY = 13;
    const ADD_TO_SHOPPINGLIST = 14;
    const ASSOCIATE = 15;
    const WEIGHT_BELOW_TARE = 16;
    const WEIGHT_UNCHANGED = 17;
    const WEIGHT_CHANGED = 18;
    const WEIGHT_REQUIRED = 19;
    const CONSUME_ALL = 20;
    const NO_STOCK = 21;
    }

$sent_lastBarcode = null;
$sent_lastProduct = null;
$send_lastState = null;

// public $id;
// public $name;
// public $barcodes = null;
// public $unit = null;
// public $stockAmount = "0";
// public $isTare;
// public $tareWeight;
// public $quFactor;
// public $defaultBestBeforeDays;
// public $creationDate;

function getProductInfo(): bool {
    global $config, $sent_lastBarcode, $sent_lastProduct;

    if ($sent_lastBarcode != $config["LAST_BARCODE"] || $sent_lastProduct != $config["LAST_PRODUCT"]) {
        $sent_lastBarcode = $config["LAST_BARCODE"];
        $sent_lastProduct = $config["LAST_PRODUCT"];
        return true; // there was an update
    } else {
        return false; // no update
    }
    
}

function getTransactionState(): bool {
    $mode = DatabaseConnection::getInstance()->getTransactionState();
    global $send_lastState;
    if ($send_lastState != $mode) {
        $send_lastState = $mode;
        return true; // there was an update
    } else {
        return false; // no update
    }
}








class LogNoPluginOutput extends LogOutput {

    /**
     *
     * If we use the regular createLog() function, it will create a loop 
     *
     * @return string
     * @throws DbConnectionDuringEstablishException
     */
    public function createLog(): string {
        global $LOADED_PLUGINS;

        if ($this->isError) {
            $this->websocketText = str_replace("</span>", "", $this->websocketText);
            $this->websocketText = preg_replace("/<span .*?>+/", "- WARNING: ", $this->websocketText);
        }
        $logText = str_replace('\n', " ", $this->logText);
        DatabaseConnection::getInstance()->saveLog($logText, $this->isVerbose, $this->isError);
        if ($this->sendWebsocketMessage) {
            SocketConnection::sendWebsocketMessage($this->websocketResultCode, $this->websocketText);
        }
        return $this->logText;
    }
}

function bbuddy_mqtt_sendMQTT($eventType, $log): void {
    global $sent_lastBarcode, $sent_lastProduct, $send_lastState;
    try {
        // Create a new instance of an MQTT client and configure it to use the shared broker host and port.
        $client = new MqttClient(MQTT_BROKER_HOST, MQTT_BROKER_PORT, 'barcode-buddy');
        
        // Connect to the broker with the configured connection settings and with a clean session.
        $client->connect(AUTHORIZATION_USERNAME, AUTHORIZATION_PASSWORD, null, true);        


        if (getProductInfo()) {
            $payload = array(   'product' => $sent_lastProduct,
                                'barcode' => $sent_lastBarcode);
            $json_payload = json_encode($payload);
            $client->publish('barcode-buddy/scan-info', $json_payload, MqttClient::QOS_AT_MOST_ONCE);
        }


        if (getTransactionState()) {
            $payload = array(   'state' => $send_lastState);
            $json_payload = json_encode($payload);
            $client->publish('barcode-buddy/state-info', $json_payload, MqttClient::QOS_AT_MOST_ONCE);
        }

        $raw_data = array(   'log' => $log,
                            'eventtype' => $eventType, 
                            'eventtype_text' => EVENT_TYPES[$eventType]
                        );



        $parsed_data = array('type' => null, 'data' => null);












        switch ($eventType) {
            case EVENT_TYPE_ERROR:
                // Invalid barcode found
                // Sent when a barcode that, after sanitizing, is empty
                break;
            case EVENT_TYPE_MODE_CHANGE:
                if (strpos($log, "Set state to") === 0) {
                    // mode change
                    $parsed_data['type'] = EventType::MODE_CHANGE;
                } elseif (strpos($log, "Set quantity to") === 0) {
                    // retroactive quantity update
                    // extract quantity from $log like "Set quantity to $quantity for barcode $lastBarcode"
                    $pattern = '/Set quantity to (.*?) for barcode/';
                    if (preg_match($pattern, $log, $matches)) {
                        // $matches[1] will contain the quantity
                        $quantity = $matches[1];
                    } else {
                        $quantity = null;
                    }
                    $parsed_data['type'] = EventType::RETRO_QUANTITY_UPDATE;
                    $parsed_data['data'] = array('quantity' => $quantity);
                } else {
                    // Unknown event
                }
                break;
            case EVENT_TYPE_CONSUME:
                // Unused
                break;
            case EVENT_TYPE_CONSUME_S:
                // Unused
                break;
            case EVENT_TYPE_PURCHASE:
                // Unused
                break;
            case EVENT_TYPE_OPEN:
                // Unused
                break;
            case EVENT_TYPE_INVENTORY:
                // Unused
                break;
            case EVENT_TYPE_EXEC_CHORE:
                // Executed a chore
                $parsed_data['type'] = EventType::EXEC_CHORE;
                break;
            case EVENT_TYPE_ADD_KNOWN_BARCODE:
                if (strpos($log, "Consuming") === 0) {
                    // User pressed consume button in web ui
                    // $pattern = '/Consuming (\d+) (\w+) of/';
                    // if (preg_match($pattern, $log, $matches)) {
                    //     // $matches[1] will contain the quantity
                    //     $amount = $matches[1];
                    //     $unit = $matches[2];
                    // } else {
                    //     $amount = null;
                    //     $unit = null;
                    // }
                    $parsed_data['type'] = EventType::IMPORT_AND_CONSUME;
                } elseif (strpos($log, "None in stock, not consuming") === 0) {
                    // User pressed consume button in web ui, but no stock available to consume
                    $parsed_data['type'] = EventType::IMPORT_AND_CONSUME_FAIL;
                } elseif (strpos($log, "Adding") === 0) {
                    // User pressed add button in web ui
                    // $pattern = '/Adding (\d+) (\w+) of/';
                    // if (preg_match($pattern, $log, $matches)) {
                    //     // $matches[1] will contain the quantity
                    //     $amount = $matches[1];
                    //     $unit = $matches[2];
                    // } else {
                    //     $amount = null;
                    //     $unit = null;
                    // }
                    $parsed_data['type'] = EventType::IMPORT_AND_PURCHASE;
                } else {
                    // Unknown event
                }
                break;
            case EVENT_TYPE_ADD_NEW_BARCODE:
                if (strpos($log, "Unknown product already scanned. Increasing quantity") === 0) {
                    // user scanned a product that has been scanned before but is still unknown
                    $parsed_data['type'] = EventType::SCAN_UNKNOWN_INC;
                } elseif (strpos($log, "Unknown barcode looked up, found name: ") === 0) {
                    // user scanned a product that has not been scanned before, but was found in the grocy database
                    $productName = substr($log, strlen("Unknown barcode looked up, found name: "));
                    $parsed_data['type'] = EventType::SCAN_UNKNOWN_FOUND;
                    $parsed_data['data'] = array('productName' => $productName);
                } else {
                    // Unknown event
                }
                break;
            case EVENT_TYPE_ADD_UNKNOWN_BARCODE:
                // user scanned a barcode that is not in the grocy database
                $parsed_data['type'] = EventType::SCAN_UNKNOWN_NOT_FOUND;
                break;
            case EVENT_TYPE_CONSUME_PRODUCT:
                // Consumed a known product
                // $pattern = '/Consuming (\d+) (\w+) of/';
                // if (preg_match($pattern, $log, $matches)) {
                //     // $matches[1] will contain the amountToConsume
                //     $amount = $matches[1];
                //     $unit = $matches[2];
                // } else {
                //     $amount = null;
                //     $unit = null;
                // }
                $parsed_data['type'] = EventType::CONSUME;
                break;
                case EVENT_TYPE_CONSUME_S_PRODUCT:
                    // Consumed a known product, tag as spoiled
                    // $pattern = '/Consuming (\d+) spoiled (\w+) of/';
                    // if (preg_match($pattern, $log, $matches)) {
                    //     // $matches[1] will contain the amountToConsume
                    //     $amount = $matches[1];
                    //     $unit = $matches[2];
                    // } else {
                    //     $amount = null;
                    //     $unit = null;
                    // }
                    $parsed_data['type'] = EventType::CONSUME_SPOILED;
                break;
            case EVENT_TYPE_PURCHASE_PRODUCT:
                // Purchased a known product at default quantity
                // $pattern = '/Adding (\d+) (\w+) of/';
                // if (preg_match($pattern, $log, $matches)) {
                //     // $matches[1] will contain the amount
                //     $amount = $matches[1];
                //     $unit = $matches[2];
                // } else {
                //     $amount = null;
                //     $unit = null;
                // }
                $parsed_data['type'] = EventType::PURCHASE;
                break;
            case EVENT_TYPE_OPEN_PRODUCT:
                // Opened a known product
                // $pattern = '/Opening (\d+) (\w+) of/';
                // if (preg_match($pattern, $log, $matches)) {
                //     // $matches[1] will contain the amount
                //     $amount = $matches[1];
                //     $unit = $matches[2];
                // } else {
                //     $amount = null;
                //     $unit = null;
                // }
                $parsed_data['type'] = EventType::OPEN;
                break;
            case EVENT_TYPE_GET_STOCK_PRODUCT:
                // Get the stock (inventory) of a known product
                $parsed_data['type'] = EventType::INVENTORY;
                break;
            case EVENT_TYPE_ADD_TO_SHOPPINGLIST:
                // Add a (known product) to the shopping list
                $parsed_data['type'] = EventType::ADD_TO_SHOPPINGLIST;
                break;
            case EVENT_TYPE_ASSOCIATE_PRODUCT:
                // Only used when adding/updating a barcode object in grocy
                if (strpos($log, "Associated barcode") === 0) {
                    // added a new barcode to a product
                    $parsed_data['type'] = EventType::ASSOCIATE;
                } elseif (strpos($log, "Set quantity to") === 0) {
                    // updated the quantity of a product via web ui button
                    $parsed_data['type'] = EventType::ASSOCIATE;
                } else {
                    // Unknown event
                }
                break;
            case EVENT_TYPE_ACTION_REQUIRED:
                // Weight-related product was just scanned
                // Weight for such products is stored in stockAmount
                // Tare weight is stored in tareWeight
                if ($sent_lastProduct) {
                    $parsed_data['data'] = array('newWeight' => $sent_lastProduct->stockAmount, 'tareWeight' => $sent_lastProduct->tareWeight);
                }
    
                if (strpos($log, "Entered weight for") === 0) {
                    // User entered weight below tare via web ui
                    $parsed_data['type'] = EventType::WEIGHT_BELOW_TARE;
                } elseif (strpos($log, "Weight unchanged for") === 0) {
                    // User entered the same weight as the current stock weight via web ui
                    $parsed_data['type'] = EventType::WEIGHT_UNCHANGED;
                } elseif (strpos($log, "Weight set to") === 0) {
                    // User entered weight via web ui, resulted in a change
                    $parsed_data['type'] = EventType::WEIGHT_CHANGED;
                } elseif (strpos($log, "Action required: Enter weight for") === 0) {
                    // User scanned a product that requires a weight to be entered in the web ui
                    $parsed_data['type'] = EventType::WEIGHT_REQUIRED;
                } else {
                    // Unknown event
                }
                break;
            case EVENT_TYPE_CONSUME_ALL_PRODUCT:
                // Consume all stock of a known product
                $parsed_data['type'] = EventType::CONSUME_ALL;
                break;
            case EVENT_TYPE_NO_STOCK:
                // Product not in stock yet a consume/open action was attempted
                $parsed_data['type'] = EventType::NO_STOCK;
                break;
            default:
                // Unknown event
                break;
        }














        $payload = array(   'raw' => $raw_data, 
                            'parsed' => $parsed_data);
        $json_payload = json_encode($payload);

        // Publish the log message on the topic 'barcode-buddy/'+ using QoS 0.
        $client->publish('barcode-buddy/event', $json_payload, MqttClient::QOS_AT_MOST_ONCE);

        // Gracefully terminate the connection to the broker.
        // $client->disconnect();
    } catch (MqttClientException $e) {
        // MqttClientException is the base exception of all exceptions in the library. Catching it will catch all MQTT related exceptions.
        $log = new LogNoPluginOutput('Publishing a message using QoS 0 failed. An exception occurred!', EVENT_TYPE_PLUGIN);
        $log->setVerbose()->dontSendWebsocket()->createLog();
    }
}


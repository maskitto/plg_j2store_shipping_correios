<?php
/**
 * @package J2Store
* @copyright Copyright (c)2016-17 Airton Torres / alphahost.net.br
* @license GNU GPL v3 or later
*/
// No direct access to this file
defined ( '_JEXEC' ) or die ();

require_once (JPATH_ADMINISTRATOR.'/components/com_j2store/library/plugins/shipping.php');

class plgJ2StoreShipping_Correios extends J2StoreShippingPlugin
{
	/**
	 * @var $_element  string  Should always correspond with the plugin's filename,
	 *                         forcing it to be unique
	 */
    var $_element   = 'shipping_correios';
    private $_isLog      = false;
	private $correios_username = '';
	private $correios_password = '';

    function __construct($subject, $config) {
    	parent::__construct($subject, $config);
    	$this->correios_username = trim($this->params->get('correios_username', ''));
    	$this->correios_password = trim($this->params->get('correios_password', ''));
    	$this->_isLog      = $this->params->get('show_debug')?true:false;
    }

	/**
	 * Method to get shipping rates from CORREIOS
	 *
	 * @param string $element
	 * @param object $order
	 * @return an array of shopping rates
	 */

   function onJ2StoreGetShippingRates($element, $order)
    {
    	$rates = array();
    	//initialise system variables
    	$app = JFactory::getApplication();
    	$db = JFactory::getDbo();

    	// Check if this is the right plugin
    	if (!$this->_isMe($element))
        {
            return $rates;
        }
       
        //set the address
        $order->setAddress();

        //get the shipping address
        $address = $order->getShippingAddress();
        
        $rates = $this->getRates($address,$order);
    	return $rates;
    }

    /**
     * Method to get rates from the CORREIOS shipping API
     *
     * @param array $address
     * @return array rates array
     */
	    		
    private function getRates($address,$order) {

    	$rates = array();

    	$shipping_status = false;
    	//first check if shippable items are in cart
    	$products = $order->getItems();
    	
    	$currencyObject = J2Store::currency();
    	$store_address = J2Store::config();

    	$domestic_services = $this->params->get('domestic_services');
    	$quote_data = array();
    	$method_data = array();
    	$weight = 0;
        $totalPrice = 0;
    	$weightObject = J2Store::weight();
    	foreach ($products as $product) {
    		if (isset($product->cartitem->shipping) && $product->cartitem->shipping) {
    			$weight_class_id = isset($product->cartitem->weight_class_id) ? $product->cartitem->weight_class_id : $store_address->get('config_weight_class_id');
    			$pro_weight = $weightObject->convert($product->cartitem->weight, $weight_class_id, $this->params->get('usps_weight_class_id'));
				for ($i = 0; $i < $product->orderitem_quantity;$i++){
					$weight += $pro_weight;
				}
                        $shipping_status = true;
    		}
    	}

    	if($shipping_status === false) return $rates;

        //$countryObject = $this->getCountry($address['country_id']);
        if (!$address['country_id'] == 30) return $rates;

    	$postcode = str_replace(' ', '', $address['postal_code']);
        $postcode = str_replace('-', '', $postcode);

        $useDeclaredValue = $this->params->get('correios_declared_value', 0);
        $declaredValue = $useDeclaredValue ? intval($totalPrice) : 0;
        $displayTime = $this->params->get('correios_display_time', 0);

        $nCdEmpresa = $this->correios_username;
        $sDsSenha = $this->correios_password;
        $sCepOrigem = $this->params->get('correios_postcode');
        $sCepDestino = $postcode;
        $VlPeso =  $weight;
        $nCdFormato = $this->params->get('correios_container');
        $nVlComprimento = $this->params->get('correios_length');
        $nVlAltura = $this->params->get('correios_height');
        $nVlLargura = $this->params->get('correios_width');
        $CdMaoPropria = $this->params->get('correios_own_hand');
        $nVlValorDeclarado =  $declaredValue;
        $sCdAvisoRecebimento = $this->params->get('correios_delivery_note');
        $nCdServico = implode(',', $domestic_services);
        $nVlDiametro = 0;
        $strRetorno = 'xml';
        $nIndicaCalculo =  $displayTime ? '3' : '0';
        
        $request = 'nCdEmpresa=' . $nCdEmpresa . '&sDsSenha=' . $sDsSenha . '&sCepOrigem=' . $sCepOrigem . '&sCepDestino=' . $sCepDestino;
        $request .= '&VlPeso=' . $VlPeso . '&nCdFormato=' . $nCdFormato . '&nVlComprimento=' . $nVlComprimento . '&nVlAltura=' . $nVlAltura;
        $request .= '&nVlLargura=' . $nVlLargura . '&CdMaoPropria=' . $CdMaoPropria . '&nVlValorDeclarado=' . $nVlValorDeclarado;
        $request .= '&sCdAvisoRecebimento=' . $sCdAvisoRecebimento . '&nCdServico=' . $nCdServico;
        $request .= '&nVlDiametro=0&strRetorno=xml&nIndicaCalculo=' . $nIndicaCalculo;

        $result = $this->_sendRequest($request);

        if ($result) {
                if ($this->params->get('show_debug')) {
                        $this->_log("CORREIOS DATA SENT: " . urldecode($request));
                        $this->_log("CORREIOS DATA RECV: " . $result);
                }

                $dom = new DOMDocument('1.0', 'UTF-8');
                $dom->loadXml($result);
                $postages = $dom->getElementsByTagName('cServico');

                foreach ($postages as $postage)
                {
                        $rate = array();
                        $rate['name'] = JText::_ ( 'J2STORE_CORREIOS_' . $postage->getElementsByTagName('Codigo')->Item(0)->nodeValue );
                        $rate['code'] = $postage->getElementsByTagName('Codigo')->Item(0)->nodeValue;
                        $rate['price'] = (double)str_replace(',', '.', $postage->getElementsByTagName('Valor')->Item(0)->nodeValue);
                        $rate['extra'] = (double)$this->getHandlingCast($rate['price']);
                        $rate['total'] = $rate['price'] + $rate['extra'];
                        $rate['tax'] = 0.00;
                        $rate['element'] = $this->_element;
                        $rates[] = $rate;
                }
        }

    	return $rates;
    }
    
    public function getHandlingCast($price){
    	$handling_percent = $this->params->get ( 'correios_handling_percent', 0 );
    	$handling_fixed = $this->params->get ( 'correios_handling_fix', 0 );
    	$handling = 0;
    	if(( float ) $handling_percent > 0 || ( float ) $handling_fixed > 0){
    		// percentage
    		if (( float ) $handling_percent > 0) {
    			$handling += ($price * ( float ) $handling_percent) / 100;
    		}
    		
    		if (( float ) $handling_fixed > 0) {
    			$handling += ( float ) $handling_fixed;
    		}
    	}
    	return $handling;
    }
    
    /**
     *
     * @param string $request
     * @return mixed
     */

    private function _sendRequest($request) {
    	$url = $this->_getActionUrl();

    	$curl = curl_init();

    	curl_setopt($curl, CURLOPT_URL, $url.'?'.$request);
    	curl_setopt($curl, CURLOPT_HEADER, 0);
    	curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);

    	$result = curl_exec($curl);

    	curl_close($curl);

    	$result = str_replace("\r\n", '', $result);
    	$result = str_replace('\"', '"', $result);

    	return $result;
    }

    /**
     *
     * @return string USPS shipping API url
     */

    private function _getActionUrl() {

        $url = 'http://ws.correios.com.br/calculador/CalcPrecoPrazo.aspx';

    	return $url;
    }

    /**
     * Simple logger
     *
     * @param string $text
     * @param string $type
     * @return void
     */
    function _log($text, $type = 'message')
    {
    	if ($this->_isLog) {
    		$file = JPATH_ROOT . "/cache/{$this->_element}.log";
    		$date = JFactory::getDate();

    		$f = fopen($file, 'a');
    		fwrite($f, "\n\n" . $date->format('Y-m-d H:i:s'));
    		fwrite($f, "\n" . $type . ': ' . $text);
    		fclose($f);
    	}
    }


 function getCountries() {
		$country = array(
					'AF' => 'Afghanistan',
					'AL' => 'Albania',
					'DZ' => 'Algeria',
					'AD' => 'Andorra',
					'AO' => 'Angola',
					'AI' => 'Anguilla',
					'AG' => 'Antigua and Barbuda',
					'AR' => 'Argentina',
					'AM' => 'Armenia',
					'AW' => 'Aruba',
					'AU' => 'Australia',
					'AT' => 'Austria',
					'AZ' => 'Azerbaijan',
					'BS' => 'Bahamas',
					'BH' => 'Bahrain',
					'BD' => 'Bangladesh',
					'BB' => 'Barbados',
					'BY' => 'Belarus',
					'BE' => 'Belgium',
					'BZ' => 'Belize',
					'BJ' => 'Benin',
					'BM' => 'Bermuda',
					'BT' => 'Bhutan',
					'BO' => 'Bolivia',
					'BA' => 'Bosnia-Herzegovina',
					'BW' => 'Botswana',
					'BR' => 'Brazil',
					'VG' => 'British Virgin Islands',
					'BN' => 'Brunei Darussalam',
					'BG' => 'Bulgaria',
					'BF' => 'Burkina Faso',
					'MM' => 'Burma',
					'BI' => 'Burundi',
					'KH' => 'Cambodia',
					'CM' => 'Cameroon',
					'CA' => 'Canada',
					'CV' => 'Cape Verde',
					'KY' => 'Cayman Islands',
					'CF' => 'Central African Republic',
					'TD' => 'Chad',
					'CL' => 'Chile',
					'CN' => 'China',
					'CX' => 'Christmas Island (Australia)',
					'CC' => 'Cocos Island (Australia)',
					'CO' => 'Colombia',
					'KM' => 'Comoros',
					'CG' => 'Congo (Brazzaville),Republic of the',
					'ZR' => 'Congo, Democratic Republic of the',
					'CK' => 'Cook Islands (New Zealand)',
					'CR' => 'Costa Rica',
					'CI' => 'Cote d\'Ivoire (Ivory Coast)',
					'HR' => 'Croatia',
					'CU' => 'Cuba',
					'CY' => 'Cyprus',
					'CZ' => 'Czech Republic',
					'DK' => 'Denmark',
					'DJ' => 'Djibouti',
					'DM' => 'Dominica',
					'DO' => 'Dominican Republic',
					'TP' => 'East Timor (Indonesia)',
					'EC' => 'Ecuador',
					'EG' => 'Egypt',
					'SV' => 'El Salvador',
					'GQ' => 'Equatorial Guinea',
					'ER' => 'Eritrea',
					'EE' => 'Estonia',
					'ET' => 'Ethiopia',
					'FK' => 'Falkland Islands',
					'FO' => 'Faroe Islands',
					'FJ' => 'Fiji',
					'FI' => 'Finland',
					'FR' => 'France',
					'GF' => 'French Guiana',
					'PF' => 'French Polynesia',
					'GA' => 'Gabon',
					'GM' => 'Gambia',
					'GE' => 'Georgia, Republic of',
					'DE' => 'Germany',
					'GH' => 'Ghana',
					'GI' => 'Gibraltar',
					'GB' => 'Great Britain and Northern Ireland',
					'GR' => 'Greece',
					'GL' => 'Greenland',
					'GD' => 'Grenada',
					'GP' => 'Guadeloupe',
					'GT' => 'Guatemala',
					'GN' => 'Guinea',
					'GW' => 'Guinea-Bissau',
					'GY' => 'Guyana',
					'HT' => 'Haiti',
					'HN' => 'Honduras',
					'HK' => 'Hong Kong',
					'HU' => 'Hungary',
					'IS' => 'Iceland',
					'IN' => 'India',
					'ID' => 'Indonesia',
					'IR' => 'Iran',
					'IQ' => 'Iraq',
					'IE' => 'Ireland',
					'IL' => 'Israel',
					'IT' => 'Italy',
					'JM' => 'Jamaica',
					'JP' => 'Japan',
					'JO' => 'Jordan',
					'KZ' => 'Kazakhstan',
					'KE' => 'Kenya',
					'KI' => 'Kiribati',
					'KW' => 'Kuwait',
					'KG' => 'Kyrgyzstan',
					'LA' => 'Laos',
					'LV' => 'Latvia',
					'LB' => 'Lebanon',
					'LS' => 'Lesotho',
					'LR' => 'Liberia',
					'LY' => 'Libya',
					'LI' => 'Liechtenstein',
					'LT' => 'Lithuania',
					'LU' => 'Luxembourg',
					'MO' => 'Macao',
					'MK' => 'Macedonia, Republic of',
					'MG' => 'Madagascar',
					'MW' => 'Malawi',
					'MY' => 'Malaysia',
					'MV' => 'Maldives',
					'ML' => 'Mali',
					'MT' => 'Malta',
					'MQ' => 'Martinique',
					'MR' => 'Mauritania',
					'MU' => 'Mauritius',
					'YT' => 'Mayotte (France)',
					'MX' => 'Mexico',
					'MD' => 'Moldova',
					'MC' => 'Monaco (France)',
					'MN' => 'Mongolia',
					'MS' => 'Montserrat',
					'MA' => 'Morocco',
					'MZ' => 'Mozambique',
					'NA' => 'Namibia',
					'NR' => 'Nauru',
					'NP' => 'Nepal',
					'NL' => 'Netherlands',
					'AN' => 'Netherlands Antilles',
					'NC' => 'New Caledonia',
					'NZ' => 'New Zealand',
					'NI' => 'Nicaragua',
					'NE' => 'Niger',
					'NG' => 'Nigeria',
					'KP' => 'North Korea (Korea, Democratic People\'s Republic of)',
					'NO' => 'Norway',
					'OM' => 'Oman',
					'PK' => 'Pakistan',
					'PA' => 'Panama',
					'PG' => 'Papua New Guinea',
					'PY' => 'Paraguay',
					'PE' => 'Peru',
					'PH' => 'Philippines',
					'PN' => 'Pitcairn Island',
					'PL' => 'Poland',
					'PT' => 'Portugal',
					'QA' => 'Qatar',
					'RE' => 'Reunion',
					'RO' => 'Romania',
					'RU' => 'Russia',
					'RW' => 'Rwanda',
					'SH' => 'Saint Helena',
					'KN' => 'Saint Kitts (St. Christopher and Nevis)',
					'LC' => 'Saint Lucia',
					'PM' => 'Saint Pierre and Miquelon',
					'VC' => 'Saint Vincent and the Grenadines',
					'SM' => 'San Marino',
					'ST' => 'Sao Tome and Principe',
					'SA' => 'Saudi Arabia',
					'SN' => 'Senegal',
					'RS' => 'Serbia',
					'SC' => 'Seychelles',
					'SL' => 'Sierra Leone',
					'SG' => 'Singapore',
					'SK' => 'Slovak Republic',
					'SI' => 'Slovenia',
					'SB' => 'Solomon Islands',
					'SO' => 'Somalia',
					'ZA' => 'South Africa',
					'GS' => 'South Georgia (Falkland Islands)',
					'KR' => 'South Korea (Korea, Republic of)',
					'ES' => 'Spain',
					'LK' => 'Sri Lanka',
					'SD' => 'Sudan',
					'SR' => 'Suriname',
					'SZ' => 'Swaziland',
					'SE' => 'Sweden',
					'CH' => 'Switzerland',
					'SY' => 'Syrian Arab Republic',
					'TW' => 'Taiwan',
					'TJ' => 'Tajikistan',
					'TZ' => 'Tanzania',
					'TH' => 'Thailand',
					'TG' => 'Togo',
					'TK' => 'Tokelau (Union) Group (Western Samoa)',
					'TO' => 'Tonga',
					'TT' => 'Trinidad and Tobago',
					'TN' => 'Tunisia',
					'TR' => 'Turkey',
					'TM' => 'Turkmenistan',
					'TC' => 'Turks and Caicos Islands',
					'TV' => 'Tuvalu',
					'UG' => 'Uganda',
					'UA' => 'Ukraine',
					'AE' => 'United Arab Emirates',
					'UY' => 'Uruguay',
					'UZ' => 'Uzbekistan',
					'VU' => 'Vanuatu',
					'VA' => 'Vatican City',
					'VE' => 'Venezuela',
					'VN' => 'Vietnam',
					'WF' => 'Wallis and Futuna Islands',
					'WS' => 'Western Samoa',
					'YE' => 'Yemen',
					'ZM' => 'Zambia',
					'ZW' => 'Zimbabwe'
				);
		
    //	$db = JFactory::getDbo();
    //	$query = $db->getQuery(true);
    //	$query->select('*')->from('#__j2store_countries');
    //	$db->setQuery($query);
    	return $country;
    }   
    
    function getCountry($country_id) {
    	$db = JFactory::getDbo();
    	$query = $db->getQuery(true);
    	$query->select('*')->from('#__j2store_countries')->where('country_id='.$db->q($country_id));
    	$db->setQuery($query);
    	return $db->loadObject();
    }
}

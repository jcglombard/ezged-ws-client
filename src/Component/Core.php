<?php

/*
 * The MIT License
 *
 * Copyright 2019 Jean-Claude GLOMBARD <jc.glombard@gmail.com>.
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 */

namespace JcgDev\EzGEDWsClient\Component;

use GuzzleHttp\Client as GuzzleHttpClient;
use JcgDev\EzGEDWsClient\Component\ServiceConfig;
use Psr\Http\Message\ResponseInterface;

/**
 *
 * @author Jean-Claude GLOMBARD <jc.glombard@gmail.com>
 */
class Core
{
    const STATUSCODE_OK = 0;

    const REQ_AUTH = 'sec/authenticate';
    const REQ_AUTH_KEEPALIVE = 'secses/keepalive';
    const REQ_LOGOUT = 'secses/delete';
    const REQ_GET_PERIMETER = 'query/gettreearchive';
    const REQ_REQUEST_VIEW = 'query/getexec';

    const REQ_UPLOAD = 'upload';


    private $guzzle;

    private $statusCode;
    private $statusMsg;
    private $rawJsonResponse;
    private $response;
    private $errorCode;
    private $errorMessage;

    private $confServices;


    private function _initConfServices() {
        $this->confServices = [];

        // Authent: sec/authenticate
        $this->confServices[ self::REQ_AUTH ] = (new ServiceConfig())
                ->setEndpoint('service.php')
                ->setService('sec/authenticate')
                ->setMethod('GET')
                ->setQuery([
                    'login' => '',
                    'pwd' => '',
                ])
                ->setResponseFilter([
                    'sessionid',
                ]);

        // KeepAlive: secses/keepalive
        $this->confServices[ self::REQ_AUTH_KEEPALIVE ] = (new ServiceConfig())
                ->setEndpoint('service.php')
                ->setService('secses/keepalive')
                ->setMethod('GET')
                ->setResponseFilter([
                    'countsignbook',
                    'countcorrection',
                    'counttrash',
                    'countmessage',
                    'countworkflow',
                ]);

        // Logout: secses/delete
        $this->confServices[ self::REQ_LOGOUT ] = (new ServiceConfig())
                ->setEndpoint('service.php')
                ->setService('secses/delete')
                ->setMethod('GET')
                ->setQuery([
                    'sessionid' => '',
                    'secsesid' => '',
                ])
                ->setResponseFilter([]);


        // Lister les vues de l'utilisateur: query/gettreearchive
        $this->confServices[ self::REQ_GET_PERIMETER ] = (new ServiceConfig())
                ->setEndpoint('service.php')
                ->setService('query/gettreearchive')
                ->setMethod('GET')
                ->setResponseFilter([]);

        // Afficher les résultats d'une vue: query/gettreearchive
        $this->confServices[ self::REQ_REQUEST_VIEW ] = (new ServiceConfig())
                ->setEndpoint('service.php')
                ->setService('query/getexec')
                ->setMethod('GET')
                ->setQuery([
                    'qryid' => '',
                    'limitstart' => 0,
                    'limitgridlines' => 20,

                    'qryusrffqn' => null,
                    'qryusrop' => null,
                    'qryusrval' => null,
                ])
                ->setResponseFilter([]);
        
        // Upload d'un Fichier
        $this->confServices[ self::REQ_UPLOAD ] = (new ServiceConfig())
                ->setEndpoint('pupload.php')
                ->setMethod('POST')
                ->setQuery([
                    'mode' => 'file',

                    'name' => null,
                    'waitdir' => null,
                ])
                ->setResponseFilter([
                    'filePath'
                ]);

    }

    /**
     *
     * @param string $serviceKey
     * @return ServiceConfig
     */
    private function getServiceConfig( string $serviceKey ) {
        return array_key_exists($serviceKey, $this->confServices) ? $this->confServices[$serviceKey] : null;
    }


    private function _stateFill( ResponseInterface $response ) {
        $this->statusCode = $response->getStatusCode();
        $this->statusMsg = $response->getReasonPhrase();
    }

    private function _stateReset() {
        $this->statusCode = null;
        $this->statusMsg = null;
        $this->rawJsonResponse = null;
        $this->response = null;
        $this->errorCode = null;
        $this->errorMessage = null;
    }

    private function _normalizeRawJsonResponse( ResponseInterface $response ) {
        $contentTypes = implode('::',$response->getHeader('Content-Type'));
        $isJson = (false !== strpos($contentTypes,'application/json'));
        $isText = (false !== strpos($contentTypes,'text/plain'));

        if ( !$isJson && !$isText ) {
            $this->errorCode = 0;
            $this->errorMessage = sprintf('Response Content-Type: %s',$contentTypes);
            return;
        }

        $this->rawJsonResponse = json_decode( (string) $response->getBody() );

        if ( null !== $this->rawJsonResponse ) {
            $_rawJson = $this->rawJsonResponse;
            if ( property_exists($_rawJson,'success') ) {
                $this->rawJsonResponse->errorCode = (true == $_rawJson->success) ? 0 : -1;
            }

            if ( property_exists($_rawJson,'message') ) {
                $this->rawJsonResponse->errorMessage = $_rawJson->message;
            }

            if ( !property_exists($_rawJson,'rows') ) {
                $_row = [];
                foreach ( $_rawJson as $key => $value ) {
                    if ( !in_array($key,['success','message','errorcode','errormsg']) ) {
                        $_row[ $key ] = $value;
                    }
                }
                $this->rawJsonResponse->rows = [ (object)$_row ];
            }

            $this->errorCode = property_exists($this->rawJsonResponse,'errorcode') ? $this->rawJsonResponse->errorcode : 100;
            $this->errorMessage = property_exists($this->rawJsonResponse,'errormsg') ? $this->rawJsonResponse->errormsg : '-!-';
        }
        
        return;
    }

    private function parseResponse( ResponseInterface $response, array $filter = null) {

        $this->_normalizeRawJsonResponse($response);

        if ( null == $this->rawJsonResponse ) {
            return $response->getBody();
        }

        $rows = is_array($this->rawJsonResponse->rows) ? $this->rawJsonResponse->rows : [ $this->rawJsonResponse->rows ];

        if ( empty($rows) || empty($filter) ) {
            return $rows;
        }

        $filtered = [];
        foreach ($rows as $row) {
            $_f = [];
            foreach ($filter as $key) {
                if ( property_exists($row,$key) ) {
                    $_f[ $key ] = $row->$key;
                }
            }
            $filtered[] = (object)$_f;
        }

        return $filtered;

    }


    /**
     *
     * @param string $ezgedUrl
     * @param null|ressource $httpRequestTraceHandler
     */
    public function __construct( string $ezgedUrl, $httpRequestTraceHandler = null )
    {
        $this->_stateReset();
        $this->_initConfServices();

        $options = [
            'base_uri' => rtrim($ezgedUrl,'/') . '/data/',
            'cookies' => true,
        ];

        if ( is_resource($httpRequestTraceHandler) ) {
            $options['debug'] = $httpRequestTraceHandler;
        }

        $this->guzzle = new GuzzleHttpClient($options);
    }


    public function exec( string $serviceKey, array $params = [], array $options = [] ) {
        $this->_stateReset();

        $sconf = $this->getServiceConfig($serviceKey);

        $_options = array_merge([
            'query' => $sconf->buildRequestQuery($params),
            'decode_content' => true,
        ], $options);

        $_response = $this->guzzle->request($sconf->getMethod(), $sconf->getEndpoint(), $_options);

        $this->_stateFill($_response);

        $this->response = $this->parseResponse($_response, $sconf->getResponseFilter());

        return;
    }

    
    public function getStatusCode() {
        return (int) $this->statusCode;
    }

    public function getStatusMsg() {
        return $this->statusMsg;
    }
    
    public function getErrorCode() {
        return (int) $this->errorCode;
    }

    public function getErrorMessage() {
        return $this->errorMessage;
    }

    public function getRawJsonResponse() {
        return $this->rawJsonResponse;
    }

    public function getResponse() {
        return $this->response;
    }

}

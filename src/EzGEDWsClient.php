<?php

/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

namespace JcgDev\EzGEDWsClient;

use GuzzleHttp\Psr7;
use JcgDev\EzGEDWsClient\Component\Core;

/**
 *
 * @author jc.glombard@gmail.com
 */
class EzGEDWsClient
{
    /**
     *
     * @var Core
     */
    private $requester;

    private $apiUser;
    private $apiPwd;

    private $sessionid;
    private $isKeepalive;

    private $traceLogHandler;
    private $_called;
    private $_args;


    /**
     * On s'assure de bien fermer la session
     */
    public function __destruct()
    {
        if ( !$this->isKeepalive() ) {
            $this->logout();
        }
        $this->traceLogHandler = null;
    }

    /**
     *
     * @param string $ezgedUrl  ex: http://localhost/ezged3
     * @param string $apiUser
     * @param string $apiPwd
     * @param null|ressource $httpRequestTraceHandler
     */
    public function __construct(string $ezgedUrl, string $apiUser, string $apiPwd, $httpRequestTraceHandler = null)
    {
        $this->apiUser = $apiUser;
        $this->apiPwd = md5($apiPwd);

        $this->sessionid = null;
        $this->isKeepalive = false;

        $this->requester = new Core($ezgedUrl,$httpRequestTraceHandler);

        $this->traceLogHandler = null;
        $this->_called = '';
        $this->_args = [];

    }


    private function _setTraceParam( string $calledMethod, array $param = []) {
        $this->_called = $calledMethod;
        $this->_args = $param;
        return $this;
    }


    /**
     *
     * @param string $traceLogFilename
     * @param string $mode
     * @return $this
     */
    public function setTraceLogHandler(string $traceLogFilename, string $mode = 'w') {
        $traceLogHandler = new \SplFileObject($traceLogFilename,$mode);
        if ( $traceLogHandler->isWritable() ) {
            $this->traceLogHandler = $traceLogHandler;
        }
        return $this;
    }

    public function getSessionId() {
        return $this->sessionid;
    }

    public function getRequestStatusCode() {
        return $this->requester->getStatusCode();
    }

    public function getRequestStatusMessage() {
        return $this->requester->getStatusMsg();
    }

    public function getErrorCode() {
        return $this->requester->getErrorCode();
    }

    public function getErrorMessage() {
        return $this->requester->getErrorMessage();
    }

    public function getRawJsonResponse() {
        return $this->requester->getRawJsonResponse();
    }

    public function getResponse() {
        return $this->requester->getResponse();
    }

    public function isSucceed() {
        return (Core::ERRORCODE_OK === $this->getErrorCode());
    }

    public function isKeepAlive() {
        return (bool)$this->isKeepalive;
    }


    public function trace( $withRaw = false ) {

        if ( null !== $this->traceLogHandler ) {
            $reqKey = $this->_called;
            $alive = $this->isKeepAlive() ? '|keepalive| ' : '';
            $log = [];
            $log[] = sprintf("---------- %s ( %s %s) ----------", $reqKey,$this->getSessionId(),$alive);
            if ( !empty($this->_args) ) {
                foreach ($this->_args as $key => $value) {
                    $_val = ( is_null($value) || is_scalar ($value) ) ? $value : json_encode($value);
                    $log[] =  sprintf("  ~ %s: %s",$key,$_val);
                }
                $log[] =  sprintf("---------- ", $reqKey);
            }
            $log[] =  sprintf("  STATUS >> [ %s ] - %s", $this->getRequestStatusCode(),$this->getRequestStatusMessage());
            $log[] =  sprintf("   ERROR >> [ %s ] - %s", $this->getErrorCode(),$this->getErrorMessage());
            if ( $withRaw ) {
                $log[] =  sprintf(" RAW >> %s", json_encode($this->getRawJsonResponse(),JSON_PRETTY_PRINT) );
            }
            $log[] =  sprintf("RESPONSE >> ", $reqKey);
            $log[] =  sprintf("%s ", json_encode($this->getResponse(),JSON_PRETTY_PRINT));
            $log[] =  sprintf("-------------------- ^ --------------------\n", $reqKey);
            $log[] = "\n";

            $this->traceLogHandler->fwrite( implode("\n",$log) );
        }

        return $this;
    }

    /**
     *
     * @param bool|null $keepalive indique si la connexion doit être maintenue
     * @return $this
     */
    public function connect ( bool $keepalive = null ) {
        if ( null === $this->sessionid ) {
            $_params = [
                'login' => $this->apiUser,
                'pwd' => $this->apiPwd,
            ];
            $this->_setTraceParam(__METHOD__)
                 ->requester->exec(Core::REQ_AUTH, $_params);

            if ( $this->isSucceed() ) {
                $r = $this->getResponse();
                $this->sessionid = $r[0]->sessionid;
            }
        }

        if ( $keepalive && $this->getSessionId() && !$this->isKeepalive() ) {
            $this->requester->exec(Core::REQ_AUTH_KEEPALIVE);
            if ( $this->isSucceed() ) {
                $this->isKeepalive = true;
            }
        }
        return $this;
    }

    /**
     *
     * @return $this
     */
    public function logout () {
        if ( null !== $this->sessionid ) {
            $_params = [
                'sessionid' => $this->sessionid,
                'secsesid' => $this->sessionid,
            ];
            $this->_setTraceParam(__METHOD__)
                 ->requester->exec(Core::REQ_LOGOUT, $_params);

            if ( $this->isSucceed() ) {
                $this->sessionid = null;
                $this->isKeepalive = false;
            }
        }
        return $this;
    }

    /**
     *  Lister les vues de l'utilisateur
     * @return $this
     */
    public function getPerimeter () {
        $this->connect()
             ->_setTraceParam(__METHOD__)
             ->requester->exec(Core::REQ_GET_PERIMETER);
        return $this;
    }

    /**
     *  Afficher les résultats d'une vue
     *
     * le paramètre $filter permet de filtrer la recherche
     * Il doit être de la forme:
     * [
     *   'field'    => Nom du champ de la base de donnée sur lequel rechercher
     *   'operator' => operateur: '=' | '>=' | '<=' | 'like'
     *   'value'    => Valeur à rechercher
     * ]
     *
     *
     * @param int $idview   identifiant de la vue (QRY_ID)
     * @param int $offset   offset pour la pagination du résultat
     * @param int $limit    nombre de ligne de résulta retourné
     * @param array|null $filter  filtre de la forme ['field'=>, 'operator'=> 'value'=>]
     * @return $this
     */
    public function requestView ( int $idview, int $offset = null, int $limit = null, array $filter = null ) {

        $_params = [
            'qryid' => $idview,
            'limitstart' => $offset,
            'limitgridlines' => $limit,
        ];

        if ( !empty($filter) ) {
            $isKeyOk = (array_key_exists('field',$filter) && array_key_exists('operator',$filter) && array_key_exists('value',$filter) );
            $operator = array_key_exists('operator',$filter) ? strtolower($filter['operator']) : '--';
            $isOperatorOK = in_array($operator,['=', '>=', '<=', 'like']);

            if ( $isKeyOk && $isOperatorOK ) {
                $_params['qryusrffqn'] = $filter['field'];
                $_params['qryusrop'] = $operator;
                $_params['qryusrval'] = $filter['value'];
            }
        }

        $this->connect()
             ->_setTraceParam(__METHOD__, ['$idview'=>$idview, '$offset'=>$offset, '$limit'=>$limit, '$filter'=>$filter])
             ->requester->exec(Core::REQ_EXEC_REQUEST,$_params);

        return $this;
    }

    /**
     * Upload d'un fichier
     *
     * Tous les paramètres ($params) sont optionels
     * $params = [
     *   - 'name' => le nom qui sera indexé pour le fichier (GED)
     *                   par défaut: basename($fullFilename)
     *   - 'waitdir'  => Le nom du répertoire d'attente d'un COLD dans lequel le fichiers sera écrit
     *                   Le répertoire doit se trouver dans l'arborescence ocr/wait (e.g: C:\nchp\var\spool\ezged\instance\ocr\wait)
     * ]
     *
     *
     * @param string $fullFilename  Nom complet du fichier (ie: c:/test/fact-5678.pdf)
     * @param array $params
     * @return $this
     *
     */
    public function upload ( string $fullFilename, array $params = [] ) {

        $_params = array_merge(['name'=>basename($fullFilename), 'waitdir'=>null],$params);

        if ( !empty($_params['waitdir']) ) {
            $_params['mode'] = 'cold';
        }

        $resource = Psr7\try_fopen($fullFilename, 'r');

        $_options = [
            'multipart' => [
                [
                    'name' => 'file',
                    'filename' => $_params['name'],
                    'contents' => Psr7\stream_for($resource),
                ],
            ]
        ];

        $this->connect()
             ->_setTraceParam(__METHOD__, ['$fullFilename'=>$fullFilename, '$params'=>$params])
             ->requester->exec(Core::REQ_UPLOAD,$_params,$_options);
        return $this;
    }

    /**
     *  Retourne la liste des pages (fichier) d'un enregistrement (row)
     *
     * @param int $idrecord   identifiant (PK) de l'enregistrement (ie. 'NOTEDEFRAIS_ID')
     * @param string $recordTable   nom de la table de l'enregistrement (ie. 'NOTEDEFRAIS')
     * @param int $offset   offset pour la pagination du résultat
     * @param int $limit    nombre de ligne de résulta retourné
     * @return $this
     */
    public function getRecordPages ( int $idrecord, string $recordTable, int $offset = null, int $limit = null ) {

        $_params = [
            'docpakrsid' => $idrecord,
            'docpaktbl' => $recordTable,
            'limitstart' => $offset,
            'limitgridlines' => $limit,
        ];

        $this->connect()
             ->_setTraceParam(__METHOD__, ['$idrecord'=>$idrecord, '$recordTable'=>$recordTable, '$offset'=>$offset, '$limit'=>$limit])
             ->requester->exec(Core::REQ_GET_RECORD_FILES,$_params);

        return $this;
    }

    /**
     * Création d'un enregistrement
     *
     * @param string $recordTable
     * @param array $fields
     * @param int $idqry
     * @return $this
     */
    public function createRecord( string $recordTable, array $fields, int $idqry = null ) {

        $_params = [
            'tfqn' => $recordTable,
            'qryid' => $idqry,
            'fields' => json_encode(array_keys($fields)),
            'values' => json_encode(array_values($fields)),
        ];

        $this->connect()
             ->_setTraceParam(__METHOD__, ['$recordTable'=>$recordTable, '$idqry'=>$idqry, '$fields'=>json_encode($fields)])
             ->requester->exec(Core::REQ_CREATE_RECORD,$_params);

        return $this;
    }

    /**
     * Mise à jour d'un enregistrement
     *
     * @param int $idrecord
     * @param string $recordTable
     * @param string $primaryField
     * @param array $fields
     * @return $this
     */
    public function updateRecord( int $idrecord, string $recordTable, string $primaryField, array $fields ) {

        $_params = [
            'tfqn' => $recordTable,
            'field_ID' => $primaryField,
            'value_ID' => $idrecord,

            'fields' => json_encode(array_keys($fields)),
            'values' => json_encode(array_values($fields)),
        ];

        $this->connect()
             ->_setTraceParam(__METHOD__, ['$idrecord'=>$idrecord, '$recordTable'=>$recordTable, '$primaryField'=>$primaryField, '$fields'=>json_encode($fields)])
             ->requester->exec(Core::REQ_UPDATE_RECORD,$_params);

        return $this;
    }

    /**
     * Ajouter un fichier (image) à un enregistrement
     *
     * @param int $idrecord
     * @param string $recordTable
     * @param string $serverFilePath  Le chemin, sur le serveur, du fichier.
     * @param boolean $convertBeforeArchive FALSE pour garder le format d'origine, TRUE pour archiver seulement le fichier converti (selon le format)
     * @return $this
     */
    public function addRecordPage( int $idrecord, string $recordTable, string $serverFilePath, bool $convertBeforeArchive = false ) {

        $_params = [
            'tfqn' => $recordTable,
            'rsid' => $idrecord,
            'file' => $serverFilePath,
            'ocr' => ($convertBeforeArchive ? 0 : 1),
        ];

        $this->connect()
             ->_setTraceParam(__METHOD__, ['$idrecord'=>$idrecord, '$recordTable'=>$recordTable, '$serverFilePath'=>$serverFilePath, '$convertBeforeArchive'=>json_encode($convertBeforeArchive)])
             ->requester->exec(Core::REQ_ADD_RECORD_FILE,$_params);

        return $this;
    }

    /**
     * Connaitre le status d'un job
     *
     * @param int $idjob
     * @return $this
     */
    public function getJobStatus( int $idjob ) {

        $_params = [
            'jobqueueid' => $idjob,
        ];

        $this->connect()
             ->_setTraceParam(__METHOD__, ['$idjob'=>$idjob])
             ->requester->exec(Core::REQ_GET_JOB_STATUS,$_params);

        return $this;
    }


    public function showFile ( int $idfile, string $fileHash, string $saveFilepath = null) {

        $_params = [
            'fsfileid' => $idfile,
            'fsfileripe' => $fileHash,
            'download' => (!empty($saveFilepath) ? 1 : 0),
        ];


        $_options = [];
        if ( !empty($saveFilepath) ) {
            $_options = [
                'sink' => $saveFilepath
            ];
        }



        $this->connect()
             ->_setTraceParam(__METHOD__, ['$idfile'=>$idfile, '$fileHash'=>$fileHash, '$saveFilepath'=>$saveFilepath])
             ->requester->exec(Core::REQ_DOWNLOAD_FILE,$_params,$_options);

        return $this;
    }

}

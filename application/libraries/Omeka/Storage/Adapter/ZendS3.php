<?php
/**
 * @copyright Roy Rosenzweig Center for History and New Media, 2011
 * @license http://www.gnu.org/licenses/gpl-3.0.txt
 * @package Omeka
 */

/**
 * Cloud storage adapter for Amazon S3, using Zend's built-in service.
 *
 * Caveat: Zend's storage adapter currently does not function correctly
 * with buckets that are validly-named, but use characters that cannot
 * appear in domain names.
 *
 * @package Omeka
 */
class Omeka_Storage_Adapter_ZendS3 implements Omeka_Storage_Adapter_AdapterInterface
{
    const AWS_KEY_OPTION = 'accessKeyId';
    const AWS_SECRET_KEY_OPTION = 'secretAccessKey';
    const REGION_OPTION = 'region';
    const BUCKET_OPTION = 'bucket';
    const EXPIRATION_OPTION = 'expiration';

    /**
     * @var Zend_Service_Amazon_S3
     */
    private $_s3;

    /**
     * @var array
     */
    private $_options;

    /**
     * Set options for the storage adapter.
     *
     * @param array $options
     */
    public function __construct(array $options = array())
    {
        $this->_options = $options;

        if (array_key_exists(self::AWS_KEY_OPTION, $options)
        && array_key_exists(self::AWS_SECRET_KEY_OPTION, $options)) {
            $awsKey = $options[self::AWS_KEY_OPTION];
            $awsSecretKey = $options[self::AWS_SECRET_KEY_OPTION];
        } else {
            throw new Omeka_Storage_Exception(__('You must specify your AWS access key and secret key to use the ZendS3 storage adapter.'));
        }

        if (!array_key_exists(self::BUCKET_OPTION, $options)) {
            throw new Omeka_Storage_Exception(__('You must specify an S3 bucket name to use the ZendS3 storage adapter.'));
        }
        
        $region = @$options[self::REGION_OPTION];

        // Use Omeka_Http_Client to retry up to 3 times on timeouts
        $client = new Omeka_Http_Client;
        $client->setMaxRetries(3);
        Zend_Service_Amazon_S3::setHttpClient($client);
        
        $this->_s3 = new Zend_Service_Amazon_S3($awsKey, $awsSecretKey, $region);
    }

    public function setUp()
    {
        // Required by interface but does nothing, for the time being.
    }

    public function canStore()
    {
        $bucket = $this->_getBucketName();
        return $this->_s3->isBucketAvailable($bucket);
    }

    /**
     * Move a local file to S3 storage.
     *
     * @param string $source Local filesystem path to file.
     * @param string $dest Destination path.
     */
    public function store($source, $dest)
    {
        $objectName = $this->_getObjectName($dest);

        // If an expiration time is set, we're uploading private files
        // and using signed URLs. If not, we're uploading public files.
        if ($this->_getExpiration()) {
            $meta[Zend_Service_Amazon_S3::S3_ACL_HEADER] = Zend_Service_Amazon_S3::S3_ACL_PRIVATE;
        } else {
            $meta[Zend_Service_Amazon_S3::S3_ACL_HEADER] = Zend_Service_Amazon_S3::S3_ACL_PUBLIC_READ;
        }
            
        $status = $this->_s3->putFileStream($source, $objectName, $meta);

        if(!$status) {
            throw new Omeka_Storage_Exception(__('Unable to store file.'));
        }
        
        _log("Omeka_Storage_Adapter_ZendS3: Stored '$source' as '$objectName'.");
        unlink($source);
    }

    /**
     * Move a file between two "storage" locations.
     *
     * @param string $source Original stored path.
     * @param string $dest Destination stored path.
     */
    public function move($source, $dest)
    {
        $sourceObject = $this->_getObjectName($source);
        $destObject = $this->_getObjectName($dest);
        
        $status = $this->_s3->moveObject($sourceObject, $destObject);

        if(!$status) {
            throw new Omeka_Storage_Exception(__('Unable to move file.'));
        }

        _log("Omeka_Storage_Adapter_ZendS3: Moved '$sourceObject' to '$destObject'.");
    }

    /**
     * Remove a "stored" file.
     *
     * @param string $path
     */
    public function delete($path)
    {
        $objectName = $this->_getObjectName($path);
            
        $status = $this->_s3->removeObject($objectName);

        if(!$status) {
            if ($this->_s3->isObjectAvailable($objectName)) {
                throw new Omeka_Storage_Exception(__('Unable to delete file.'));
            } else {
                _log("Omeka_Storage_Adapter_ZendS3: Tried to delete missing object '$objectName'.", Zend_Log::WARN);
            }
        } else {
            _log("Omeka_Storage_Adapter_ZendS3: Removed object '$objectName'.");
        }
    }

    /**
     * Get a URI for a "stored" file.
     *
     * @see http://docs.amazonwebservices.com/AmazonS3/latest/dev/index.html?RESTAuthentication.html#RESTAuthenticationQueryStringAuth
     * @param string $path
     * @return string URI
     */
    public function getUri($path)
    {
        $endpoint = $this->_s3->getEndpoint();
        $object = urlencode($this->_getObjectName($path));

        $uri = "$endpoint/$object";

        if ($expiration = $this->_getExpiration()) {
            $date = new Zend_Date();
            $date->add($expiration, Zend_Date::MINUTE);

            $accessKeyId = $this->_options[self::AWS_KEY_OPTION];
            $secretKey = $this->_options[self::AWS_SECRET_KEY_OPTION];

            $expires = $date->getTimestamp();
            $stringToSign = "GET\n\n\n$expires\n/$object";

            $signature = base64_encode(
                Zend_Crypt_Hmac::compute($secretKey, 'sha1',
                    utf8_encode($stringToSign), Zend_Crypt_Hmac::BINARY));

            $query['AWSAccessKeyId'] = $accessKeyId;
            $query['Expires'] = $expires;
            $query['Signature'] = $signature;

            $queryString = http_build_query($query);

            $uri .= "?$queryString";
        }

        return $uri;
    }

    /**
     * Get the name of the bucket files should be stored in.
     * 
     * @return string Bucket name
     */
    private function _getBucketName()
    {
        return $this->_options[self::BUCKET_OPTION];
    }

    /**
     * Get the object name.  Zend's S3 service requires you to build the
     * object name by prepending the name of the target bucket.
     *
     * @param string $path
     * @return string Object name.
     */
    private function _getObjectName($path)
    {
        return $this->_getBucketName() . '/' . $path;
    }

    /**
     * Normalizes and returns the expiration time.
     *
     * Converts to integer and returns zero for all non-positive numbers.
     *
     * @return int
     */
    private function _getExpiration()
    {
        $expiration = (int) @$this->_options[self::EXPIRATION_OPTION];
        return $expiration > 0 ? $expiration : 0;
    }
}

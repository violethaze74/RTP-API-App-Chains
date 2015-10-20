<?php

class AppChains
{
	/**
	 * Security token supplied by the client
	 */
	private $token;
	
	/**
	 * Remote hostname to send requests to
	 */
	private $chainsHostname;
	
	/**
	 * Schema to access remote API (http or https)
	 */
	const DEFAULT_APPCHAINS_SCHEMA = "https"; 
	
	/**
	 * Port to access remote API
	 */
	const DEFAULT_APPCHAINS_PORT = 443;
	
	/**
	 * Timeout to wait between tries to update Job status in seconds
	 */
	const DEFAULT_REPORT_RETRY_TIMEOUT = 1;
	
	/**
	 * Default hostname for Beacon requests
	 */
	const BEACON_HOSTNAME = "beacon.sequencing.com";
	
	/**
	 * Default AppChains protocol version
	 */
	const PROTOCOL_VERSION = "v1";
	
	/**
	 * Constructor
	 * Takes 2 optional arguments
	 * @param token OAuth security token
	 * @param chainsHostname hostname to call
	 */
	public function __construct($token = null, $chainsHostname = null)
	{
		if ($token)
			$this->token = $token;
		
		if ($chainsHostname)
			$this->chainsHostname = $chainsHostname;
	}
	
	// High level public API
	
	/**
	 * Requests report
	 * @param remoteMethodName REST endpoint name (i.e. StartApp)
	 * @param applicationMethodName report/application specific identifier (i.e. MelanomaDsAppv)
	 * @param datasourceId resource with data to use for report generation
	 * @return
	 */
	public function getReport($remoteMethodName, $applicationMethodName, $datasourceId)
	{
		$job = $this->submitReportJob("POST", $remoteMethodName, $applicationMethodName, $datasourceId);
		return $this->getReportImpl($job);
	}
	
	/**
	 * Requests report
	 * @param remoteMethodName REST endpoint name (i.e. StartApp)
	 * @param requestBody jsonified request body to send to server
	 * @return
	 */
	public function getReportEx($remoteMethodName, $requestBody)
	{
		$job = $this->submitReportJobImpl("POST", $remoteMethodName, $requestBody);
		return $this->getReportImpl($job);
	}
	
	/**
	 * Returns sequencing beacon
	 * @return
	 */
	public function getSequencingBeacon($chrom, $pos, $allele)
	{
		return $this->getBeacon("SequencingBeacon", $this->getBeaconParameters($chrom, $pos, $allele));
	}
	
	/**
	 * Returns public beacon
	 * @return
	 */
	public function getPublicBeacon($chrom, $pos, $allele)
	{
		return $this->getBeacon("PublicBeacons", $this->getBeaconParameters($chrom, $pos, $allele));
	}
	
	// Low level public API

	/**
	 * Requests report in raw form it is sent from the server
	 * without parsing and transforming it
	 * @param remoteMethodName REST endpoint name (i.e. StartApp)
	 * @param applicationMethodName report/application specific identifier (i.e. MelanomaDsAppv)
	 * @param datasourceId resource with data to use for report generation
	 * @return
	 */
	public function getRawReport($remoteMethodName, $applicationMethodName, $datasourceId)
	{
		$job = $this->submitReportJob("POST", $remoteMethodName, $applicationMethodName, $datasourceId);
		return $this->getRawReportImpl($job)->getSource();
	}
	
	/**
	 * Requests report in raw form it is sent from the server
	 * without parsing and transforming it
	 * @param remoteMethodName REST endpoint name (i.e. StartApp)
	 * @param requestBody jsonified request body to send to server
	 * @return
	 */
	public function getRawReportEx($remoteMethodName, $requestBody)
	{
		$job = $this->submitReportJobImpl("POST", $remoteMethodName, $requestBody);
		return $this->getRawReportImpl($job)->getSource();
	}

	/**
	 * Returns beacon
	 * @param methodName REST endpoint name (i.e. PublicBeacons)
	 * @param parameters map of request (GET) parameters (key->value) to append to the URL
	 * @return
	 */
	public function getBeacon($methodName, $parameters)
	{
		return $this->getBeaconEx($methodName, $this->getRequestString($parameters));
	}
	
	/**
	 * Returns beacon
	 * @param methodName REST endpoint name (i.e. PublicBeacons)
	 * @param parameters query string
	 * @return
	 */
	public function getBeaconEx($methodName, $queryString)
	{
		$response = $this->httpRequest("GET", $this->getBeaconUrl($methodName, $queryString));
		return $response->getResponseData();
	}

	// Internal methods

	protected function getBeaconParameters($chrom, $pos, $allele)
	{
		return array("chrom" => $chrom, "pos" => $pos, "allele" => $allele);
	}

	/**
	 * Retrieves report data from the API server
	 * @param job identifier to retrieve report
	 * @return report
	 */
	protected function getReportImpl($job)
	{
		return $this->processCompletedJob($this->getRawReportImpl($job));
	}

	/**
	 * Retrieves report data from the API server
	 * @param job identifier to retrieve report
	 * @return report
	 */
	protected function getRawReportImpl($job)
	{
		while (true)
		{
			$rawResult = $this->getRawJobResult($job);
			if ($rawResult->isCompleted())
			{
				return $rawResult;
			}
			
			sleep(self::DEFAULT_REPORT_RETRY_TIMEOUT);
		}
	}
	
	/**
	 * Handles raw report result by transforming it to user friendly state
	 * @param rawResult
	 * @return
	 */
	protected function processCompletedJob($rawResult)
	{
		$results = array();
		
		foreach ($rawResult->getResultProps() as $resultProp)
		{
			$type = $resultProp["Type"];
			$resultPropValue = $resultProp["Value"];
			$resultPropName = $resultProp["Name"];
			
			if (is_null($type) || is_null($resultPropValue) || is_null($resultPropName))
				continue;
			
			$resultPropType = strtolower($type);
			
			switch ($resultPropType)
			{
				case "plaintext":
					$results[] = new Result($resultPropName, new TextResultValue($resultPropValue));
				break;
				case "pdf":
					$filename = sprintf("report_%d.%s", $rawResult->getJobId(), $resultPropType);
					$reportFileUrl = $this->getReportFileUrl($resultPropValue);
					
					$resultValue = new FileResultValue($this, $filename, $resultPropType, $reportFileUrl);
					$results[] = new Result($resultPropName, $resultValue);
				break;
			}
		}
		
		$finalResult = new Report();
		$finalResult->setSucceeded($rawResult->isSucceeded());
		$finalResult->setResults($results);
		
		return $finalResult;
	}
	
	/**
	 * Retrieves raw job results data
	 * @param job job identifier
	 * @return raw job results
	 */
	protected function getRawJobResult($job)
	{
		$url = $this->getJobResultsUrl($job->getJobId());
		
		$httpResponse = $this->httpRequest("GET", $url);
		$decodedResponse = json_decode($httpResponse->getResponseData(), true);
		
		$resultProps = $decodedResponse["ResultProps"];
		$status = $decodedResponse["Status"];
		
		$succeeded = false;
		
		if (is_null($status["CompletedSuccesfully"]))
			$succeeded = false;
		else
			$succeeded = (bool) $status["CompletedSuccesfully"];
		
		$jobStatus = $status["Status"];
		
		$result = new RawReportJobResult();
		$result->setSource($decodedResponse);
		$result->setJobId($job->getJobId());
		$result->setSucceeded($succeeded);
		$result->setCompleted(!strcasecmp($jobStatus, "completed") || !strcasecmp($jobStatus, "failed"));
		$result->setResultProps($resultProps);
		$result->setStatus($jobStatus);
		
		return $result;
	}
	
	/**
	 * Builds request body used for report generation
	 * @param applicationMethodName
	 * @param datasourceId
	 * @return
	 */
	protected function buildReportRequestBody($applicationMethodName, $datasourceId)
	{
		$parameters = array(
				"Name" => "dataSourceId", 
				"Value" => $datasourceId);
				
		$data = array(
				"AppCode" => $applicationMethodName,
				"Pars" => array($parameters));
				
		return $data;
	}
	
	/**
	 * Submits job to the API server
	 * @param httpMethod HTTP method to access API server
	 * @param remoteMethodName REST endpoint name (i.e. StartApp)
	 * @param applicationMethodName report/application specific identifier (i.e. MelanomaDsAppv)
	 * @param datasourceId resource with data to use for report generation
	 * @return job identifier
	 */
	protected function submitReportJob($httpMethod, $remoteMethodName, $applicationMethodName, $datasourceId)
	{
		return $this->submitReportJobImpl($httpMethod, $remoteMethodName,
			json_encode($this->buildReportRequestBody($applicationMethodName, $datasourceId)));
	}
	
	/**
	 * Submits job to the API server
	 * @param httpMethod httpMethod HTTP method to access API server
	 * @param remoteMethodName REST endpoint name (i.e. StartApp)
	 * @param requestBody jsonified request body to send to server
	 * @return
	 */
	protected function submitReportJobImpl($httpMethod, $remoteMethodName, $requestBody)
	{
		$httpResponse = $this->httpRequest($httpMethod, $this->getJobSubmissionUrl($remoteMethodName), $requestBody);
		
		$responseCode = $httpResponse->getResponseCode();
		$responseData = $httpResponse->getResponseData();
		
		if ($responseCode != 200)
			throw new Exception(sprintf("Appchains returned error HTTP code %d with message %s",
				$responseCode, $responseData));
		
		$decodedResponse = json_decode($httpResponse->getResponseData(), true);
		$jobId = $decodedResponse["jobId"];
				
		if (!is_numeric($jobId))
			throw new Exception("Appchains returned invalid job identifier");
		
		return new Job($jobId);
	}
	
	/**
	 * Constructs URL for getting report file
	 * @param fileId file identifier
	 * @return URL
	 */
	protected function getReportFileUrl($fileId)
	{
		return sprintf("%s/GetReportFile?id=%d", $this->getBaseAppChainsUrl(), $fileId);
	}
	
	/**
	 * Constructs URL for getting job results
	 * @param jobId job identifier
	 * @return URL
	 */
	protected function getJobResultsUrl($jobId) 
	{
		return sprintf("%s/GetAppResults?idJob=%d", $this->getBaseAppChainsUrl(), $jobId);
	}
	
	/**
	 * Constructs URL for job submission
	 * @param applicationMethodName report/application specific identifier (i.e. MelanomaDsAppv)
	 * @return
	 */
	protected function getJobSubmissionUrl($applicationMethodName)
	{
		return sprintf("%s/%s", $this->getBaseAppChainsUrl(), $applicationMethodName);
	}
	
	/**
	 * Constructs base Appchains URL
	 * @return
	 */
	protected function getBaseAppChainsUrl()
	{
		return sprintf("%s://%s:%d/%s/", self::DEFAULT_APPCHAINS_SCHEMA,
			$this->chainsHostname, self::DEFAULT_APPCHAINS_PORT, self::PROTOCOL_VERSION);
	}
	
	/**
	 * Constructs URL for accessing beacon related remote endpoints
	 * @param methodName report/application specific identifier (i.e. SequencingBeacon)
	 * @param queryString query string
	 * @return
	 */
	protected function getBeaconUrl($methodName, $queryString)
	{
		return sprintf("%s://%s:%d/%s/?%s", self::DEFAULT_APPCHAINS_SCHEMA,
			self::BEACON_HOSTNAME, self::DEFAULT_APPCHAINS_PORT, $methodName, $queryString);
	}
	
	/**
	 * Downloads remote file
	 * @param method HTTP method (GET/POST)
	 * @param url URL to send request to
	 * @param file path to local file to save file to
	 * @return
	 */
	public function downloadFile($method, $url, $file)
	{
		$fileHandle = fopen($file, "w");
		$this->httpRequest($method, $url, null, array(CURLOPT_FILE => $fileHandle));
		fclose($fileHandle);
	}
	
	/**
	 * Executes HTTP request of the specified type
	 * @param method HTTP method (GET/POST)
	 * @param url URL to send request to
	 * @param body request body (applicable for POST)
	 * @param curlAttributes additional cURL attributes
	 * @return
	 */
	protected function httpRequest($method, $url, $body = null, $curlAttributes = null)
	{
		$curlHandle = $this->httpRequestImpl($method, $url, $body, $curlAttributes);
			
		$responseBody = curl_exec($curlHandle);
		$responseCode = curl_getinfo($curlHandle, CURLINFO_HTTP_CODE);
		
		if ($errorCode = curl_errno($curlHandle))
		{
		    throw new Exception("Error retrieving data from $url ($method, '$body'): " . curl_error($curlHandle));
		}
		
		curl_close($curlHandle);
		
		return new HttpResponse($responseCode, $responseBody);
	}
	
	/**
	 * Executes HTTP request of the specified type and returns cURL handle
	 * @param method HTTP method (GET/POST)
	 * @param url URL to send request to
	 * @param body request body (applicable for POST)
	 * @param curlAttributes additional cURL attributes
	 * @return
	 */
	public function httpRequestImpl($method, $url, $body = null, $curlAttributes = null)
	{
		$method = strtoupper($method);
		if (!in_array($method, array("GET", "POST")))
			throw new Exception("Unsupported HTTP method '$method'");
		
		$curlHandle = null;
		
		switch ($method)
		{
			case 'GET':
				$curlHandle = $this->createHttpGetConnection($url);
			break;
			case 'POST':
				$curlHandle = $this->createHttpPostConnection($url, $body);
			break;
		}
		
		if (!is_null($curlAttributes))
		{
			curl_setopt_array($curlHandle, $curlAttributes);
		}
		
		return $curlHandle;
	}
	
	/**
	 * Creates and returns HTTP connection cURL object using POST method
	 * @param url URL to send request to
	 * @param body request body (applicable for POST)
	 * @return cURL handle
	 */
	protected function createHttpPostConnection($url, $body)
	{
		$curlHandle = curl_init();
		
		$httpHeaders = array_merge($this->getOauthHeaders(), 
			array("Content-Type: application/json",
				  "Content-Length: " . strlen($body)));

		curl_setopt($curlHandle, CURLOPT_URL, $url);
		curl_setopt($curlHandle, CURLOPT_POSTFIELDS, $body);
		curl_setopt($curlHandle, CURLOPT_POST, true);
		curl_setopt($curlHandle, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($curlHandle, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($curlHandle, CURLOPT_HTTPHEADER, $httpHeaders);
		
		return $curlHandle;
	}
	
	/**
	 * Creates and returns HTTP connection cURL object using GET method
	 * @param url URL to send request to
	 * @return cURL handle
	 */
	protected function createHttpGetConnection($url)
	{
		$curlHandle = curl_init();
		curl_setopt($curlHandle, CURLOPT_URL, $url);
		curl_setopt($curlHandle, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($curlHandle, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($curlHandle, CURLOPT_HTTPHEADER, $this->getOauthHeaders());
		
		return $curlHandle;
	}
	
	/**
	 * Configures cURL handle by adding authorization headers
	 * @param curlHandle cURL handle
	 */
	protected function getOauthHeaders()
	{
		if (!$this->token)
			return array();
		
		return array("Authorization: Bearer " . $this->token);
	}
	
	/**
	 * Generates query string by map of key=value pairs
	 * @param urlParameters map of key-value pairs
	 * @return
	 */
	protected function getRequestString($urlParameters)
	{
		$request = "";
		
		foreach ($urlParameters as $k => $v)
		{
			if (strlen($request) > 0)
				$request .= "&";
			
			$request .= sprintf("%s=%s", urlencode($k), urlencode($v));
		}
		
		return $request;
	}
}

/**
 * Class that represents generic HTTP response
 */
class HttpResponse
{
	private $responseCode;
	private $responseData;
	
	public function __construct($responseCode, $responseData)
	{
		$this->responseCode = $responseCode;
		$this->responseData = $responseData;
	}
	
	public function getResponseCode()
	{
		return $this->responseCode;
	}
	
	public function getResponseData()
	{
		return $this->responseData;
	}
}

/**
 * Class that represents generic job identifier 
 */
class Job
{
	private $jobId;
	
	public function __construct($jobId)
	{
		$this->jobId = $jobId;
	}
	
	public function getJobId()
	{
		return $this->jobId;
	}
}

/**
 * Class that represents unstructured job response
 */
class RawReportJobResult
{
	private $jobId;
	private $succeeded;
	private $completed;
	private $status;
	private $source;
	private $resultProps;
	
	public function getResultProps()
	{
		return $this->resultProps;
	}
	
	public function setResultProps($resultProps)
	{
		$this->resultProps = $resultProps;
	}
	
	public function getStatus()
	{
		return $this->status;
	}
	
	public function setStatus($status)
	{
		$this->status = $status;
	}

	public function isCompleted()
	{
		return $this->completed;
	}
	
	public function setCompleted($completed)
	{
		$this->completed = $completed;
	}
	
	public function isSucceeded()
	{
		return $this->succeeded;
	}
	
	public function setSucceeded($succeeded)
	{
		$this->succeeded = $succeeded;
	}	

	public function getJobId()
	{
		return $this->jobId;
	}
	
	public function setJobId($jobId)
	{
		$this->jobId = $jobId;
	}	

	public function getSource()
	{
		return $this->source;
	}
	
	public function setSource($source)
	{
		$this->source = $source;
	}
}

/**
 * Class that represents report available to
 * the end client
 */
class Report
{
	private $succeeded;
	private $results;
	
	public function isSucceeded()
	{
		return $this->succeeded;
	}
	
	public function setSucceeded($succeeded)
	{
		$this->succeeded = $succeeded;
	}
	
	public function getResults()
	{
		return $this->results;
	}
	
	public function setResults($results)
	{
		$this->results = $results;
	}
}

/**
 * Enumerates possible result entity types
 */
class ResultType
{
	const FILE = 0;
	const TEXT = 1;
}

class ResultValue
{
	private $type;
	
	public function __construct($type)
	{
		$this->type = $type;
	}
	
	public function getType()
	{
		return $this->type;
	}
}

/**
 * Class that represents result entity if plain text string
 */
class TextResultValue extends ResultValue
{
	private $data;
	
	public function __construct($data)
	{
		parent::__construct(ResultType::TEXT);
		$this->data = $data;
	}
	
	public function getData()
	{
		return $this->data;
	}
}

/**
 * Class that represents result entity if it's file
 */
class FileResultValue  extends ResultValue
{
	private $name;
	private $extension;
	private $url;
	private $chains;
	
	public function __construct($chains, $name, $extension, $url)
	{
		parent::__construct(ResultType::FILE);
		
		$this->chains = $chains;
		$this->name = $name;
		$this->extension = $extension;
		$this->url = $url;
	}
	
	public function getName()
	{
		return $this->name;
	}
	
	public function getUrl()
	{
		return $this->url;
	}
	
	public function getExtension()
	{
		return $this->extension;
	}
	
	public function saveAs($fullPathWithName)
	{
		$this->chains->downloadFile("GET", $this->getUrl(), $fullPathWithName);
	}
	
	public function saveTo($location)
	{
		$this->saveAs(sprintf("%s/%s", $location, $this->getName()));
	}
}

/**
 * Class that represents single report result entity
 */
class Result
{
	private $value;
	private $name;
	
	public function __construct($name, $resultValue)
	{
		$this->name = $name;
		$this->value = $resultValue;
	}
	
	public function getValue()
	{
		return $this->value;
	}
	
	public function getName()
	{
		return $this->name;
	}
}

?>


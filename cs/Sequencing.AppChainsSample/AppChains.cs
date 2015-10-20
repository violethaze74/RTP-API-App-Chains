using System;
using System.Collections.Generic;
using System.Threading.Tasks;
using Sequencing.AppChainsSample.SQAPI;

namespace Sequencing.AppChainsSample
{
    /// <summary>
    /// High level wrapper for accessing appchains functionality
    /// </summary>
    public class AppChains
    {
        /// <summary>
        /// Security token supplied by the client
        /// </summary>
        private string token;

        /// <summary>
        /// Service facade for SQAPI
        /// </summary>
        private readonly BackendServiceFacade backendFacade;

        /// <summary>
        /// Service facade for beacons
        /// </summary>
        private readonly BeaconFacade beaconFacade;

        /// <summary>
        /// Timeout to wait between tries to update Job status in seconds
        /// </summary>
        private const int DEFAULT_REPORT_RETRY_TIMEOUT = 1;

        /// <summary>
        /// Constructor that should be called in order to work  with methods that require authentication (i.e. GetReport)
        /// </summary>
        /// <param name="token">OAuth security token</param>
        /// <param name="chainsUrl">hostname to call</param>
        /// <param name="beaconsUrl"></param>
        public AppChains(string token, string chainsUrl, string beaconsUrl) : this(beaconsUrl)
        {
            backendFacade = new BackendServiceFacade(token, chainsUrl);
        }

        /// <summary>
        /// Constructor that should be called in order to work with methods that doesn't require authentication (i.e. GetBeacon)
        /// </summary>
        /// <param name="beaconsUrl"></param>
        public AppChains(string beaconsUrl)
        {
            beaconFacade = new BeaconFacade(beaconsUrl);
        }

        /// <summary>
        /// High level public API. Requests report.
        /// </summary>
        /// <param name="applicationMethodName">report/application specific identifier (i.e. MelanomaDsAppv)</param>
        /// <param name="datasourceId">resource with data to use for report generation</param>
        /// <returns></returns>
        public Report GetReport(string applicationMethodName, string datasourceId)
        {
            var _startApp = backendFacade.StartApp(new AppStartParams{AppCode = applicationMethodName, 
                                                                      Pars = {new NewJobParameter("dataSourceId", datasourceId)}});
            return GetReportImpl(new Job(_startApp));
        }
  
        /// <summary>
        /// Returns sequencing beacon
        /// </summary>
        /// <param name="chrom"></param>
        /// <param name="pos"></param>
        /// <param name="allele"></param>
        /// <returns></returns>
        public string GetSequencingBeacon(int chrom, int pos, string allele)
        {
            return beaconFacade.GetSequencingBeacon(chrom, pos, allele);
        }

        /// <summary>
        /// Returns public beacon
        /// </summary>
        /// <param name="chrom"></param>
        /// <param name="pos"></param>
        /// <param name="allele"></param>
        /// <returns></returns>
        public string GetPublicBeacon(int chrom, int pos, string allele)
        {
            return beaconFacade.GetPublicBeacon(chrom, pos, allele);
        }

        // Low level public API

        /// <summary>
        ///   Requests report in raw form it is sent from the server
        /// without parsing and transforming it
        /// </summary>
        /// <param name="remoteMethodName">REST endpoint name (i.e. StartApp)</param>
        /// <param name="applicationMethodName">report/application specific identifier (i.e. MelanomaDsAppv)</param>
        /// <param name="datasourceId">resource with data to use for report generation</param>
        /// <returns></returns>
        public AppResultsHolder GetRawReport(string applicationMethodName, string datasourceId)
        {
            var _startApp = backendFacade.StartApp(new AppStartParams{AppCode = applicationMethodName, 
                                                                      Pars = {new NewJobParameter("dataSourceId", datasourceId)}});
            return GetRawReportImpl(new Job(_startApp));
        }
       
        /// <summary>
        /// Retrieves report data from the API server
        /// </summary>
        /// <param name="job">identifier to retrieve report</param>
        /// <returns></returns>
        protected Report GetReportImpl(Job job)
        {
            return processCompletedJob(GetRawReportImpl(job));
        }

        /// <summary>
        /// Retrieves raw report data from the API server
        /// </summary>
        /// <param name="job">job id</param>
        /// <returns></returns>
        protected AppResultsHolder GetRawReportImpl(Job job)
        {
            while (true)
            {
                try
                {
                    var rawResult = backendFacade.GetAppResults(job.JobId);
                    if (rawResult.Status.Status == "Completed")
                        return rawResult;

                    Task.Delay(DEFAULT_REPORT_RETRY_TIMEOUT).Wait();
                }
                catch (Exception e)
                {
                    throw new ApplicationException("Error processing job:"+ job.JobId, e);
                }
            }
        }

        
        /// <summary>
        /// Handles raw report result by transforming it to user friendly state
	    /// </summary>
        /// <param name="rawResult"></param>
        /// <returns></returns>
        protected Report processCompletedJob(AppResultsHolder rawResult)
        {
            var results=new List<Result>();
            foreach (var prop in rawResult.ResultProps)
            {
                string resultPropType = prop.Type.ToLower();

                switch (resultPropType)
                {
                    case "plaintext":
                        results.Add(new Result(prop.Name, new TextResultValue(prop.Value)));
                        break;
                    case "pdf":
                        string filename = string.Format("report_{0}.{1}", rawResult.Status.IdJob, resultPropType);
                        var reportFileUrl = GetReportFileUrl(prop.Value);

                        ResultValue resultValue = new FileResultValue(filename, resultPropType, reportFileUrl);
                        results.Add(new Result(prop.Name, resultValue));
                        break;
                }
            }

            Report finalResult = new Report();
            finalResult.Succeeded = rawResult.Status.CompletedSuccesfully ?? false;
            finalResult.setResults(results);

            return finalResult;
        }

        /// <summary>
        /// Constructs URL for getting report file
	    /// </summary>
        /// <param name="fileId">file identifier</param>
        /// <returns></returns>
        protected Uri GetReportFileUrl(string fileId)
        {
            var _uri = new Uri(backendFacade.ServiceUrl);
            return new Uri(_uri, string.Format("/GetReportFile?id={0}", fileId));
        }
    }
}
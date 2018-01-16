<?php

namespace MichaelDrennen\Geonames\Console;

use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use MichaelDrennen\Geonames\Models\GeoSetting;
use MichaelDrennen\Geonames\Models\Log;
use MichaelDrennen\LocalFile\LocalFile;

/**
 * Class AlternateName
 *
 * @package MichaelDrennen\Geonames\Console
 */
class AlternateName extends Command {

    use GeonamesConsoleTrait;

    /**
     * @var string The name and signature of the console command.
     */
    protected $signature = 'geonames:alternate-name 
        {--country=* : Add the 2 character code for each country. Add additional countries with additional "--country=" options on the command line.}    ';

    /**
     * @var string The console command description.
     */
    protected $description = "Populate the alternate_names table.";

    /**
     *
     */
    const REMOTE_FILE_NAME = 'alternateNames.zip';

    /**
     *
     */
    const LOCAL_ALTERNATE_NAMES_FILE_NAME = 'alternateNames.txt';


    /**
     * The name of our alternate names table in our database. Using constants here, so I don't need
     * to worry about typos in my code. My IDE will warn me if I'm sloppy.
     */
    const TABLE = 'geonames_alternate_names';

    /**
     * The name of our temporary/working table in our database.
     */
    const TABLE_WORKING = 'geonames_alternate_names_working';


    /**
     * Initialize constructor.
     */
    public function __construct() {
        parent::__construct();
    }


    /**
     * Execute the console command. The zip file this command downloads has two files in it. The alternate names, and
     * iso language codes file. The iso language codes file is available as a separate download, so for simplicity's
     * sake I handle the iso language code download and insertion in another console command.
     *
     * @return bool
     * @throws Exception
     */
    public function handle() {
        ini_set( 'memory_limit', -1 );
        $this->startTimer();
        GeoSetting::init();

        $urlsToAlternateNamesZipFiles = $this->getAlternateNameDownloadLinks( $this->option( 'country' ) );

        $absoluteLocalFilePathsOfAlternateNamesZipFiles = [];
        foreach ( $urlsToAlternateNamesZipFiles as $i => $urlsToAlternateNamesZipFile ) {
            try {
                $absoluteLocalFilePathsOfAlternateNamesZipFiles[] = $this->downloadFile( $this, $urlsToAlternateNamesZipFile );
            } catch ( Exception $e ) {
                $this->error( $e->getMessage() );
                Log::error( $urlsToAlternateNamesZipFiles, $e->getMessage(), 'remote' );

                return false;
            }
        }


        foreach ( $absoluteLocalFilePathsOfAlternateNamesZipFiles as $i => $absoluteLocalFilePathOfAlternateNamesZipFile ) {
            try {
                $this->unzip( $absoluteLocalFilePathOfAlternateNamesZipFile );
            } catch ( Exception $e ) {
                $this->error( $e->getMessage() );
                Log::error( $absoluteLocalFilePathOfAlternateNamesZipFile, $e->getMessage(), 'local' );

                return false;
            }

            $absoluteLocalFilePathOfAlternateNamesFile = $this->getLocalAbsolutePathToAlternateNamesTextFile();

            if ( ! file_exists( $absoluteLocalFilePathOfAlternateNamesFile ) ) {
                throw new Exception( "The unzipped alternateNames.txt file could not be found. We were looking for: " . $absoluteLocalFilePathOfAlternateNamesFile );
            }


            $this->insertAlternateNamesWithLoadDataInfile( $absoluteLocalFilePathOfAlternateNamesFile );
        }


        $this->info( "alternate_names data was downloaded and inserted in " . $this->getRunTime() . " seconds." );
    }

    /**
     * @param array $countryCodes The two character country code, if specified by the user.
     *
     * @return array   The absolute paths to the remote alternate names zip files.
     */
    protected function getAlternateNameDownloadLinks( array $countryCodes = [] ): array {
        if ( is_null( $countryCodes ) ):
            return [ self::$url . self::REMOTE_FILE_NAME ];
        endif;

        $alternateNameDownloadLinks = [];
        foreach ( $countryCodes as $i => $countryCode ) {
            $alternateNameDownloadLinks[] = self::$url . '/alternatenames/' . strtoupper( $countryCode ) . '.zip';
        }

        return $alternateNameDownloadLinks;

    }

    /**
     * This function is used in debugging only. The main block of code has no need for this function, since the
     * downloadFile() function returns this exact path as it's return value. The alternate names file takes a while
     * to download on my slow connection, so I save a copy of it for testing, and use this function to point to it.
     *
     * @return string The absolute local path to the downloaded zip file.
     * @throws \Exception
     */
    protected function getLocalAbsolutePathToAlternateNamesZipFile(): string {
        return GeoSetting::getAbsoluteLocalStoragePath() . DIRECTORY_SEPARATOR . self::REMOTE_FILE_NAME;
    }

    /**
     * @param string $countryCode The two character country code that a user can optionally pass in.
     *
     * @return string
     * @throws \Exception
     */
    protected function getLocalAbsolutePathToAlternateNamesTextFile( string $countryCode = null ): string {
        if ( is_null( $countryCode ) ):
            return GeoSetting::getAbsoluteLocalStoragePath() . DIRECTORY_SEPARATOR . self::LOCAL_ALTERNATE_NAMES_FILE_NAME;
        endif;
        return GeoSetting::getAbsoluteLocalStoragePath() . DIRECTORY_SEPARATOR . strtoupper( $countryCode ) . DIRECTORY_SEPARATOR . strtoupper( $countryCode ) . '.zip';

    }


    /**
     * @param $localFilePath
     *
     * @throws \Exception
     */
    protected function insertAlternateNamesWithLoadDataInfile( $localFilePath ) {
        Schema::dropIfExists( self::TABLE_WORKING );
        DB::statement( 'CREATE TABLE ' . self::TABLE_WORKING . ' LIKE ' . self::TABLE . ';' );
        $this->disableKeys( self::TABLE_WORKING );

        try {
            $localFileSplitPaths = LocalFile::split( $localFilePath, 50000, 'split_', null );
            $numSplitFiles       = count( $localFileSplitPaths );
        } catch ( Exception $exception ) {
            throw $exception;
        }


        foreach ( $localFileSplitPaths as $i => $localFileSplitPath ):
            $query = "LOAD DATA LOCAL INFILE '" . $localFileSplitPath . "'
    INTO TABLE " . self::TABLE_WORKING . "
        (   alternateNameId, 
            geonameid,
            isolanguage, 
            alternate_name, 
            isPreferredName, 
            isShortName, 
            isColloquial, 
            isHistoric,              
            @created_at, 
            @updated_at)
    SET created_at=NOW(),updated_at=null";

            $this->line( "Running the LOAD DATA INFILE query. This could take a good long while." );

            $rowsInserted = DB::connection()->getpdo()->exec( $query );
            if ( $rowsInserted === false ) {
                Log::error( '', "Unable to load data infile for alternate names.", 'database' );
                throw new \Exception( "Unable to execute the load data infile query. " . print_r( DB::connection()
                                                                                                    ->getpdo()
                                                                                                    ->errorInfo(), true ) );
            }
            $this->info( "Inserted file " . ( $i + 1 ) . " of " . $numSplitFiles );
        endforeach;


        $this->enableKeys( self::TABLE_WORKING );
        Schema::dropIfExists( self::TABLE );
        Schema::rename( self::TABLE_WORKING, self::TABLE );
    }
}

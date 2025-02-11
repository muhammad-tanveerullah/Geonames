<?php

namespace MichaelDrennen\Geonames\Console;

use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use MichaelDrennen\Geonames\Models\GeoSetting;
use MichaelDrennen\Geonames\Models\Log;

/**
 * Class IsoLanguageCodes
 *
 * @package MichaelDrennen\Geonames\Console
 */
class IsoLanguageCode extends AbstractCommand {

    use GeonamesConsoleTrait;

    /**
     * @var string The name and signature of the console command.
     */
    protected $signature = 'geonames:iso-language-code
    {--connection= : If you want to specify the name of the database connection you want used.}';

    /**
     * @var string The console command description.
     */
    protected $description = "Populate the iso_language_codes table.";


    /**
     *
     */
    const LANGUAGE_CODES_FILE_NAME = 'iso-languagecodes.txt';


    /**
     *
     */
    const TABLE = 'geonames_iso_language_codes';

    /**
     *
     */
    const TABLE_WORKING = 'geonames_iso_language_codes_working';


    /**
     * Initialize constructor.
     */
    public function __construct() {
        parent::__construct();
    }


    /**
     * Execute the console command.
     * I don't worry about creating a temp/working table here, because it runs so fast.
     * I'm only inserting a couple rows.
     *
     * @throws \Exception
     */
    public function handle() {
        ini_set( 'memory_limit', -1 );
        $this->startTimer();

        $this->connectionName = $this->option( 'connection' );

        try {
            $this->setDatabaseConnectionName();
            $this->info( "The database connection name was set to: " . $this->connectionName );
            $this->comment( "Testing database connection..." );
            $this->checkDatabase();
            $this->info( "Confirmed database connection set up correctly." );
        } catch ( \Exception $exception ) {
            $this->error( $exception->getMessage() );
            $this->stopTimer();
            throw $exception;
        }

        try {
            GeoSetting::init(
                GeoSetting::DEFAULT_COUNTRIES_TO_BE_ADDED,
                GeoSetting::DEFAULT_LANGUAGES,
                GeoSetting::DEFAULT_STORAGE_SUBDIR,
                $this->connectionName );
        } catch ( \Exception $exception ) {
            Log::error( NULL, "Unable to initialize the GeoSetting record.", '', $this->connectionName );
            $this->stopTimer();
            throw $exception;
        }

        $remotePath = self::$url . self::LANGUAGE_CODES_FILE_NAME;
        $absoluteLocalFilePathOfIsoLanguageCodesFile = self::downloadFile( $this, $remotePath, $this->connectionName );

        if ( !file_exists( $absoluteLocalFilePathOfIsoLanguageCodesFile ) ) {
            throw new Exception( "We were unable to download the file at: " . $absoluteLocalFilePathOfIsoLanguageCodesFile );
        }

        if ( $this->isRobustDriver() ):
            $this->insertIsoLanguageCodesWithLoadDataInfile( $absoluteLocalFilePathOfIsoLanguageCodesFile );
        else:
            $this->insertIsoLanguageCodesWithEloquent( $absoluteLocalFilePathOfIsoLanguageCodesFile );
        endif;


        $this->info( "iso_language_codes data was downloaded and inserted in " . $this->getRunTime() . " seconds." );

        return self::SUCCESS_EXIT;
    }


    /**
     * @param $localFilePath
     *
     * @throws \Exception
     */
    protected function insertIsoLanguageCodesWithLoadDataInfile( $localFilePath ) {
        ini_set( 'memory_limit', -1 );
        $this->line( "Inserting via LOAD DATA INFILE: " . $localFilePath );

        $this->makeWorkingTable( self::TABLE, self::TABLE_WORKING );
        $this->disableKeys( self::TABLE_WORKING );

        // This file includes a header row. That is why I skip the first line with the IGNORE 1 LINES statement.
        $query = "LOAD DATA LOCAL INFILE '" . $localFilePath . "'
    INTO TABLE " . self::TABLE_WORKING . " IGNORE 1 LINES
        (   iso_639_3, 
            iso_639_2,
            iso_639_1, 
            language_name,          
            @created_at, 
            @updated_at)
    SET created_at=NOW(),updated_at=null";

        $rowsInserted = DB::connection( $this->connectionName )->getpdo()->exec( $query );
        if ( $rowsInserted === FALSE ) {
            Log::error( '', "Unable to load data infile for iso language names.",
                        'database', $this->connectionName );
            throw new Exception( "Unable to execute the load data infile query. " . print_r( DB::connection( $this->connectionName )
                                                                                               ->getpdo()
                                                                                               ->errorInfo(), TRUE ) );
        }

        $this->enableKeys( self::TABLE_WORKING );
        Schema::connection( $this->connectionName )->dropIfExists( self::TABLE );
        Schema::connection( $this->connectionName )->rename( self::TABLE_WORKING, self::TABLE );
    }


    /**
     * @param string $localFilePath
     * @throws Exception
     */
    protected function insertIsoLanguageCodesWithEloquent( string $localFilePath ) {
        ini_set( 'memory_limit', -1 );
        $this->line( "Inserting via Eloquent: " . $localFilePath );

        $this->makeWorkingTable( self::TABLE, self::TABLE_WORKING );
        $this->disableKeys( self::TABLE_WORKING );

        $rows = [];
        $file = fopen( $localFilePath, 'r' );
        while ( ( $line = fgetcsv( $file, 0, "\t" ) ) !== FALSE ) {
            $modifiedLine = $this->formatLineForEloquent( $line );
            $rows[]       = $modifiedLine;
        }
        fclose( $file );

        array_shift( $rows ); // Remove the header row


        /**
         * @see https://github.com/laravel/framework/issues/50
         */
        $slicer = floor(999 / sizeof($rows[0]));
        $slices = array_chunk($rows, $slicer);
        foreach ($slices as $slice) {
            try {
                \MichaelDrennen\Geonames\Models\IsoLanguageCodeWorking::insert( $slice );
            } catch ( \Exception $exception ) {
                Log::error( '',
                            $exception->getMessage(),
                            'database',
                            $this->connectionName );
                $this->error( $exception->getMessage() );
                throw $exception;
            }
        }


        $this->enableKeys( self::TABLE_WORKING );
        Schema::connection( $this->connectionName )->dropIfExists( self::TABLE );
        Schema::connection( $this->connectionName )->rename( self::TABLE_WORKING, self::TABLE );
    }

    /**
     * Replaces the numerical index with the Model's field name, so that I can mass insert these.
     * @param array $isoLanguageCodeData
     * @return array
     */
    protected function formatLineForEloquent( array $isoLanguageCodeData ) {
        return [
            'iso_639_3'     => $isoLanguageCodeData[ 0 ],
            'iso_639_2'     => $isoLanguageCodeData[ 1 ],
            'iso_639_1'     => $isoLanguageCodeData[ 2 ],
            'language_name' => $isoLanguageCodeData[ 3 ]
        ];
    }
}
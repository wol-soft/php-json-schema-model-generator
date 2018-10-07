<?php

declare(strict_types = 1);

namespace RalleApp\Modelkrams\SubDirectory;

use Exception;
use InvalidArgumentException;

/**
 * This is an auto-implemented class implemented by the php-json-schema-model-generator.
 * If you need to implement something in this class use inheritance. Else you will loose your changes if the classes
 * are re-generated.
 *
 * Class AlbumModel
 * @package namespace RalleApp\Modelkrams\SubDirectory;
 */
class AlbumModel
{
    
        /** @var string */
        protected $albumTitle;
    

    /**
     * AlbumModel constructor.
     *
     * @param array $modelData
     
        *
        * @throws Exception
     
    */
    public function __construct(array $modelData)
    {
        
            $this->processAlbumTitle($modelData);
        
    }

    
        /**
         * @return string
         */
        public function getAlbumTitle(): string
        {
            return $this->albumTitle;
        }

        
            /**
             * @param string $albumTitle
             *
             * @return $this
             */
            public function setAlbumTitle(string $albumTitle): AlbumModel
            {
                $this->albumTitle = $albumTitle;
                return $this;
            }
        

        /**
         * Extract the value, perform validations and set the property albumTitle
         *
         * @param array $modelData
         *
         * @throws Exception
         */
        protected function processAlbumTitle(array $modelData): void
        {
            $value = $modelData['album-title'] ?? null;
            
                
                    if (!is_string($value)) {
                        throw new InvalidArgumentException('invalid type for album-title');
                    }
                
            
            $this->albumTitle = $value;
        }
    
}

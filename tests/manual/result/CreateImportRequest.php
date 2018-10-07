<?php

declare(strict_types = 1);

namespace RalleApp\Modelkrams;

use Exception;
use InvalidArgumentException;

/**
 * This is an auto-implemented class implemented by the php-json-schema-model-generator.
 * If you need to implement something in this class use inheritance. Else you will loose your changes if the classes
 * are re-generated.
 *
 * Class CreateImportRequest
 * @package namespace RalleApp\Modelkrams;
 */
class CreateImportRequest
{
    
        /** @var int */
        protected $importType;
    
        /** @var  */
        protected $importEnum;
    
        /** @var string */
        protected $importTitle;
    
        /** @var string */
        protected $importContentDescription;
    
        /** @var float */
        protected $userId;
    

    /**
     * CreateImportRequest constructor.
     *
     * @param array $modelData
     
        *
        * @throws Exception
     
    */
    public function __construct(array $modelData)
    {
        
            $this->processImportType($modelData);
        
            $this->processImportEnum($modelData);
        
            $this->processImportTitle($modelData);
        
            $this->processImportContentDescription($modelData);
        
            $this->processUserId($modelData);
        
    }

    
        /**
         * @return int
         */
        public function getImportType(): int
        {
            return $this->importType;
        }

        
            /**
             * @param int $importType
             *
             * @return $this
             */
            public function setImportType(int $importType): CreateImportRequest
            {
                $this->importType = $importType;
                return $this;
            }
        

        /**
         * Extract the value, perform validations and set the property importType
         *
         * @param array $modelData
         *
         * @throws Exception
         */
        protected function processImportType(array $modelData): void
        {
            $value = $modelData['import-type'] ?? null;
            
                
                    if (!in_array($value, array (
   1,
   2,
   3,
   4,
), true)) {
                        throw new InvalidArgumentException('Invalid value for import-type');
                    }
                
                    if (!is_int($value)) {
                        throw new InvalidArgumentException('invalid type for import-type');
                    }
                
            
            $this->importType = $value;
        }
    
        /**
         * @return mixed
         */
        public function getImportEnum()
        {
            return $this->importEnum;
        }

        
            /**
             * @param  $importEnum
             *
             * @return $this
             */
            public function setImportEnum( $importEnum): CreateImportRequest
            {
                $this->importEnum = $importEnum;
                return $this;
            }
        

        /**
         * Extract the value, perform validations and set the property importEnum
         *
         * @param array $modelData
         *
         * @throws Exception
         */
        protected function processImportEnum(array $modelData): void
        {
            $value = $modelData['import-enum'] ?? null;
            
                
                    if (!in_array($value, array (
   2,
   4,
   6,
), true)) {
                        throw new InvalidArgumentException('Invalid value for import-enum');
                    }
                
            
            $this->importEnum = $value;
        }
    
        /**
         * @return string
         */
        public function getImportTitle(): string
        {
            return $this->importTitle;
        }

        
            /**
             * @param string $importTitle
             *
             * @return $this
             */
            public function setImportTitle(string $importTitle): CreateImportRequest
            {
                $this->importTitle = $importTitle;
                return $this;
            }
        

        /**
         * Extract the value, perform validations and set the property importTitle
         *
         * @param array $modelData
         *
         * @throws Exception
         */
        protected function processImportTitle(array $modelData): void
        {
            $value = $modelData['import-title'] ?? null;
            
                
                    if (!is_string($value)) {
                        throw new InvalidArgumentException('invalid type for import-title');
                    }
                
            
            $this->importTitle = $value;
        }
    
        /**
         * @return string
         */
        public function getImportContentDescription(): string
        {
            return $this->importContentDescription;
        }

        
            /**
             * @param string $importContentDescription
             *
             * @return $this
             */
            public function setImportContentDescription(string $importContentDescription): CreateImportRequest
            {
                $this->importContentDescription = $importContentDescription;
                return $this;
            }
        

        /**
         * Extract the value, perform validations and set the property importContentDescription
         *
         * @param array $modelData
         *
         * @throws Exception
         */
        protected function processImportContentDescription(array $modelData): void
        {
            $value = $modelData['import-content-description'] ?? null;
            
                
                    if (!is_string($value)) {
                        throw new InvalidArgumentException('invalid type for import-content-description');
                    }
                
            
            $this->importContentDescription = $value;
        }
    
        /**
         * @return float
         */
        public function getUserId(): float
        {
            return $this->userId;
        }

        
            /**
             * @param float $userId
             *
             * @return $this
             */
            public function setUserId(float $userId): CreateImportRequest
            {
                $this->userId = $userId;
                return $this;
            }
        

        /**
         * Extract the value, perform validations and set the property userId
         *
         * @param array $modelData
         *
         * @throws Exception
         */
        protected function processUserId(array $modelData): void
        {
            $value = $modelData['user-id'] ?? null;
            
                
                    if (!isset($modelData['user-id'])) {
                        throw new InvalidArgumentException('missing required value for user-id');
                    }
                
                    if (!is_float($value)) {
                        throw new InvalidArgumentException('invalid type for user-id');
                    }
                
                    if ($value < 10) {
                        throw new InvalidArgumentException('Value for user-id must not be smaller than 10');
                    }
                
            
            $this->userId = $value;
        }
    
}

<?php
/**
 * @package nxcImport
 * @class   nxcImportController
 * @author  Serhey Dolgushev <serhey.dolgushev@nxc.no>
 * @date    05 Nov 2010
 **/

class nxcImportController
{
	public $config;
	public $counter = array(
		'skip'   => 0,
		'create' => 0,
		'update' => 0,
		'remove' => 0
	);
	public $statistics = array(
		'skip'   => array(),
		'create' => array(),
		'update' => array(),
		'remove' => array()
	);
	private $cli;
	private $pcHandler;
	private $logFileHandler;
	private $isLogEnabled;

	public function __construct( nxcImportConfig $config, $cli = false, $log = true ) {
		$this->config    = $config;
		$this->pcHandler = new nxcPowerContent( $cli );

		$this->isLogEnabled = $log;
		if( $this->isLogEnabled ) {
			$this->cli = $cli;
			$this->logFileHandler = fopen( 'var/log/import.log', 'a' );
		}
	}

	public function run( $remove = false, $useStateHashes = true, $update = true, $create = true ) {
		$contentClass = $this->config->getContentClass();
		$allOjectsInFeedRemoteIDs = array();

		$dataList      = $this->config->getDataList();
		$dataListCount = count( $dataList );
		if( $dataListCount > 0 ) {
			foreach( $dataList as $key => &$objectData ) {
				$memoryUsage = number_format( memory_get_usage( true ) / ( 1024 * 1024 ), 2 );
				$this->debug(
					number_format( $key / $dataListCount * 100, 2 ) . '% (' . ( $key + 1 ) . '/' . $dataListCount . '), Memory usage: ' . $memoryUsage . ' Mb',
					array( 'red' )
				);

				$objectData['remoteID']                = $this->config->getObjectRemoteID( $objectData );
				$objectData['mainParentNodeID']        = $this->config->getMainParentNodeID( $objectData );
				$objectData['adittionalParentNodeIDs'] = (array) $this->config->getAdittionalParentNodeIDs( $objectData );
				$objectData['attributes']              = (array) $this->config->transformObjectAttributes( $objectData );
				$objectData['language']                = $this->config->getLanguage( $objectData );
				$objectData['versionStatus']           = $this->config->getVersionStatus( $objectData );
				$objectData['mainNodePriority']        = $this->config->getMainNodePriority( $objectData );
				$objectData['visibility']              = $this->config->isVisible( $objectData );

				$currentStateHash = $this->config->getStateHash( $objectData );

				$allOjectsInFeedRemoteIDs[] = $objectData['remoteID'];

				$object = eZContentObject::fetchByRemoteID( $objectData['remoteID'] );
				$result = $this->config->preProcessCallback( $object, $objectData );
				if( $result === false ) {
					$this->debug( '[Skipped by preProcessCallback] Remote ID: "' . $objectData['remoteID'] . '"', array( 'blue' ) );
					$this->skip( $object, $objectData );
					continue;
				}

				if( $object instanceof eZContentObject ) {
					if( $update === false ) {
						$this->debug( '[Skipped] "' . $object->attribute( 'name' ) . '"', array( 'blue' ) );
						$this->skip( $object, $objectData );
					} else {
						$storedStateHash = nxcImportStateHash::get( $objectData['remoteID'] );
						if(
							$currentStateHash == $storedStateHash
							&& $useStateHashes === true
						) {
							$this->debug(
								'[Skipped] "' . $object->attribute( 'name' ) . '" (Node ID: ' . $object->attribute( 'main_node_id' ) . ')',
								array( 'blue' )
							);
							$this->skip( $object, $objectData );
						} else {
							$parentNode = false;
							if( $objectData['mainParentNodeID'] !== false ) {
								$parentNode = eZContentObjectTreeNode::fetch( $objectData['mainParentNodeID'] );
							}
							if(
								$objectData['mainParentNodeID'] !== false
								&& $parentNode instanceof eZContentObjectTreeNode === false
							) {
								$this->remove( $object, $objectData );

								nxcImportStateHash::remove( $object->attribute( 'remote_id' ) );
								$this->pcHandler->removeObject( $object );
							} else {
								$params = array(
									'object'                  => $object,
									'attributes'              => $objectData['attributes'],
									'additionalParentNodeIDs' => $objectData['adittionalParentNodeIDs'],
									'visibility'              => (bool) $objectData['visibility']
								);
								if( $objectData['mainParentNodeID'] !== false ) {
									$params['parentNode'] = $parentNode;
								}

								$this->pcHandler->updateObject( $params );
								$this->update( $object, $objectData );

								nxcImportStateHash::update( $objectData['remoteID'], $currentStateHash );
								$object->resetDataMap();
								eZContentObject::clearCache( $object->attribute( 'id' ) );
							}
						}
					}
				} else {
					if( $create === false ) {
						$this->debug( '[Skipped]', array( 'blue' ) );
						$this->skip( $object, $objectData );
					} else {
						$object = $this->pcHandler->createObject(
							array(
								'class'                   => $contentClass,
								'parentNodeID'            => $objectData['mainParentNodeID'],
								'attributes'              => $objectData['attributes'],
								'remoteID'                => $objectData['remoteID'],
								'additionalParentNodeIDs' => $objectData['adittionalParentNodeIDs'],
								'languageLocale'          => isset( $objectData['language'] ) ? $objectData['language'] : false,
								'visibility'              => (bool) $objectData['visibility']
							)
						);
						if( $object instanceof eZContentObject ) {
							$this->create( $object, $objectData );
							nxcImportStateHash::update( $objectData['remoteID'], $currentStateHash );

							if( $objectData['mainNodePriority'] !== false ) {
								$mainNode = $object->attribute( 'main_node' );
								$mainNode->setAttribute( 'priority', $objectData['mainNodePriority'] );
								$mainNode->store();
							}
							$object->resetDataMap();
							eZContentObject::clearCache( $object->attribute( 'id' ) );
						}
					}
				}

				$this->config->postProcessCallback( $object, $objectData );
			}

			$allOjectsInFeedRemoteIDs = array_unique( $allOjectsInFeedRemoteIDs );
			if( $remove && count( $allOjectsInFeedRemoteIDs ) > 0 ) {
				$publishedObjects = $contentClass->objectList();
				foreach( $publishedObjects as $object ) {
					if( in_array( $object->attribute( 'remote_id' ), $allOjectsInFeedRemoteIDs ) === false ) {
						$this->remove( $object, $objectData );

						nxcImportStateHash::remove( $object->attribute( 'remote_id' ) );
						$this->pcHandler->removeObject( $object );
					}
				}
			}
		}
	}

	public function skip( $object, array $objectData ) {
		$this->doStatAction( 'skip', $object, $objectData );
	}

	public function create( $object, array $objectData ) {
		$this->doStatAction( 'create', $object, $objectData );
	}

	public function update( $object, array $objectData ) {
		$this->doStatAction( 'update', $object, $objectData );
	}

	public function remove( $object, array $objectData ) {
		$this->doStatAction( 'remove', $object, $objectData );
	}

	private function doStatAction( $action, $object, array $objectData ) {
		$this->counter[ $action ]++;
		if( $object instanceof eZContentObject ) {
			$this->statistics[ $action ][] = $object->attribute( 'name' );
		}
	}

	public function log( $message, array $styles = array( 'green' ) ) {
		if( $this->isLogEnabled ) {
			$this->debug( $message, $styles );
			fwrite( $this->logFileHandler, '[' . date( DATE_RFC822 ) . '] ' . $message . "\n" );
		}
	}

	public function debug( $message, array $styles = array( 'green' ) ) {
		if( $this->cli instanceof eZCLI ) {
			foreach( $styles as $style ) {
				$message = $this->cli->stylize( $style, $message );
			}
			$this->cli->output( $message );
		}
	}

	public function __destruct() {
		if( $this->isLogEnabled ) {
			fwrite( $this->logFileHandler, '-------------------------------------------------------' . "\n" );
			fclose( $this->logFileHandler );
		}
	}
};

<?php
/**
 * Google Drive API endpoints using Google Client Library.
 *
 * @link          https://wpmudev.com/
 * @since         1.0.0
 *
 * @author        WPMUDEV
 * @package       WPMUDEV\PluginTest
 */

namespace WPMUDEV\PluginTest\Endpoints\V1;

defined( 'WPINC' ) || die;

use WPMUDEV\PluginTest\Base;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use Google_Client;
use Google_Service_Drive;
use Google_Service_Drive_DriveFile;

class Drive_API extends Base {

    private static $instance = null;

    public static function instance() {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private $client = null;
    private $drive_service = null;
    private $redirect_uri = '';

    private $scopes = array(
        Google_Service_Drive::DRIVE_FILE,
        Google_Service_Drive::DRIVE_READONLY,
    );

    public function init() {
        $this->redirect_uri = home_url( '/wp-json/wpmudev/v1/drive/callback' );
        $this->setup_google_client();

        add_action( 'rest_api_init', array( $this, 'register_routes' ) );
    }

    public function permission_check() {
        return current_user_can( 'manage_options' );
    }
    /**
     * New permission callback: ensures user is WP admin AND authenticated with Google Drive
     */
    public function rest_permission_authenticated() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return new WP_Error( 'forbidden', 'Unauthorized', array( 'status' => 403 ) );
        }

        if ( ! $this->ensure_valid_token() ) {
            return new WP_Error( 'no_access_token', 'Not authenticated with Google Drive', array( 'status' => 401 ) );
        }

        return true;
    }
    public function register_routes() {

        // Save credentials endpoint
        register_rest_route(
            'wpmudev/v1',
            '/drive/save-credentials',
            [
                'methods'             => 'POST',
                'callback'            => [ $this, 'save_credentials' ],
                'permission_callback' => [ $this, 'permission_check' ],
                'args'                => [
                    'client_id' => [
                        'required' => true,
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'client_secret' => [
                        'required' => true,
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                ],
            ]
        );

        // Authentication endpoint
        register_rest_route( 'wpmudev/v1/drive', '/auth', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'start_auth' ),
            'permission_callback' => array( $this, 'permission_check' ),
        ) );

        // OAuth callback
        register_rest_route( 'wpmudev/v1/drive', '/callback', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'handle_callback' ),
            'permission_callback' => '__return_true',
        ) );

        // List files
        register_rest_route( 'wpmudev/v1/drive', '/files', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'list_files' ),
            'permission_callback' => array( $this, 'rest_permission_authenticated' ),
        ) );

        // Upload
        register_rest_route( 'wpmudev/v1/drive', '/upload', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'upload_file' ),
            'permission_callback' => array( $this, 'rest_permission_authenticated' ),
        ) );

        // Download
        register_rest_route( 'wpmudev/v1/drive', '/download', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'download_file' ),
			'permission_callback' => '__return_true',
        ) );

        // Create folder
        register_rest_route( 'wpmudev/v1/drive', '/create', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'create_folder' ),
            'permission_callback' => array( $this, 'rest_permission_authenticated' ),
        ) );
    }

    private function setup_google_client() {
        $creds = get_option( 'wpmudev_plugin_tests_auth', array() );
        if ( empty( $creds['client_id'] ) || empty( $creds['client_secret'] ) ) {
            $this->client = null;
            return;
        }

        $client = new Google_Client();
        $client->setClientId( $creds['client_id'] );
        $client->setClientSecret( $creds['client_secret'] );
        $client->setRedirectUri( $this->redirect_uri );
        $client->setAccessType( 'offline' );
        $client->setScopes( $this->scopes );

        $access_token = get_option( 'wpmudev_drive_access_token', '' );
        if ( ! empty( $access_token ) ) {
            $client->setAccessToken( $access_token );
        }

        $this->client = $client;
        $this->drive_service = new Google_Service_Drive( $this->client );

		if ( $this->client && $this->client->getAccessToken() ) {
			$this->drive_service = new Google_Service_Drive( $this->client );
		} else {
			$this->drive_service = null;
		}
    }

    public function save_credentials( WP_REST_Request $request ) {
        if ( ! $this->permission_check() ) {
            return new WP_Error( 'forbidden', 'Unauthorized', array( 'status' => 403 ) );
        }

        $body = $request->get_json_params();
        $client_id = isset( $body['client_id'] ) ? sanitize_text_field( $body['client_id'] ) : '';
        $client_secret = isset( $body['client_secret'] ) ? sanitize_text_field( $body['client_secret'] ) : '';

		// Basic validation
		if ( empty( $client_id ) || empty( $client_secret ) ) {
			return new WP_Error( 'missing_credentials', 'Client ID and Client Secret are required.', array( 'status' => 400 ) );
		}

		// Format validation
		if ( ! preg_match( '/^[0-9]+-[a-z0-9]+\.apps\.googleusercontent\.com$/', $client_id ) ) {
			return new WP_Error( 'invalid_client_id', 'Invalid Client ID format.', array( 'status' => 400 ) );
		}

		if ( strlen( $client_secret ) < 20 ) {
			return new WP_Error( 'invalid_client_secret', 'Invalid Client Secret format.', array( 'status' => 400 ) );
		}
        update_option( 'wpmudev_plugin_tests_auth', array(
            'client_id'     => $client_id,
            'client_secret' => $client_secret,
        ) );

        $this->setup_google_client();

        return new WP_REST_Response( array( 'success' => true ) );
    }

    public function start_auth( WP_REST_Request $request ) {
        if ( ! $this->permission_check() ) {
            return new WP_Error( 'forbidden', 'Unauthorized', array( 'status' => 403 ) );
        }

        if ( ! $this->client ) {
            $this->setup_google_client();
        }

        if ( ! $this->client ) {
            return new WP_Error( 'no_credentials', 'Google credentials are missing. Save client ID/secret first.', array( 'status' => 400 ) );
        }

        try {
            $auth_url = $this->client->createAuthUrl();
            return new WP_REST_Response( array( 'url' => esc_url_raw( $auth_url ) ) );
        } catch ( \Exception $e ) {
            return new WP_Error( 'auth_failed', $e->getMessage(), array( 'status' => 500 ) );
        }
    }

	public function handle_callback( WP_REST_Request $request ) {
		$code = sanitize_text_field( wp_unslash( $_GET['code'] ?? '' ) );
		if ( empty( $code ) ) {
			return new WP_Error( 'missing_code', 'Missing code in callback', [ 'status' => 400 ] );
		}

		if ( ! $this->client ) {
			$this->setup_google_client();
		}

		try {
			$token = $this->client->fetchAccessTokenWithAuthCode( $code );
			if ( isset( $token['error'] ) ) {
				return new WP_Error( 'token_error', $token['error_description'] ?? 'Unknown token error', [ 'status' => 500 ] );
			}
			update_option( 'wpmudev_drive_access_token', $token );
			$this->drive_service = new Google_Service_Drive( $this->client );

			// Redirect back to admin page
			wp_redirect( admin_url( 'admin.php?page=wpmudev_plugintest_drive&auth=success' ) );
			exit;

		} catch ( Exception $e ) {
			wp_die( 'Failed to get access token: ' . esc_html( $e->getMessage() ) );
		}
	}


    private function ensure_valid_token() {
        if ( ! $this->client ) {
            $this->setup_google_client();
            if ( ! $this->client ) {
                return false;
            }
        }

        $access = get_option( 'wpmudev_drive_access_token', '' );
        if ( empty( $access ) ) {
            return false;
        }

        $this->client->setAccessToken( $access );

        if ( $this->client->isAccessTokenExpired() ) {
            try {
                if ( $this->client->getRefreshToken() ) {
                    $new_token = $this->client->fetchAccessTokenWithRefreshToken( $this->client->getRefreshToken() );
                    if ( isset( $new_token['error'] ) ) {
                        return false;
                    }
                    update_option( 'wpmudev_drive_access_token', $new_token );
                    $this->client->setAccessToken( $new_token );
					$this->drive_service = new Google_Service_Drive( $this->client ); // re-init service
                    return true;
                }
                return false;
            } catch ( \Exception $e ) {
                return false;
            }
        }

        return true;
    }

	public function list_files( WP_REST_Request $request ) {
		$perm = $this->rest_permission_authenticated();
		if ( is_wp_error( $perm ) ) {
			return $perm;
		}

		$page_size  = (int) $request->get_param( 'page_size' );
		$page_token = $request->get_param( 'page_token' );

		if ( $page_size <= 0 || $page_size > 100 ) {
			$page_size = 20; // default
		}

		try {
			$params = array(
				'pageSize'   => $page_size,
				'fields'     => 'nextPageToken, files(id, name, mimeType, size, modifiedTime, webViewLink)',
				'orderBy'    => 'modifiedTime desc',
			);

			if ( ! empty( $page_token ) ) {
				$params['pageToken'] = sanitize_text_field( $page_token );
			}

			$results = $this->drive_service->files->listFiles( $params );

			$files = array();
			foreach ( $results->getFiles() as $file ) {
				$files[] = array(
					'id'           => $file->getId(),
					'name'         => $file->getName(),
					'mimeType'     => $file->getMimeType(),
					'size'         => $file->getSize(),
					'modifiedTime' => $file->getModifiedTime(),
					'webViewLink'  => $file->getWebViewLink(),
				);
			}

			return new WP_REST_Response( array(
				'success'       => true,
				'files'         => $files,
				'nextPageToken' => $results->getNextPageToken() ?: null,
			) );

		} catch ( \Exception $e ) {
			return new WP_Error( 'list_failed', $e->getMessage(), array( 'status' => 500 ) );
		}
	}


	// public function upload_file( WP_REST_Request $request ) {
	// 	$perm = $this->rest_permission_authenticated();
	// 	if ( is_wp_error( $perm ) ) {
	// 		return $perm;
	// 	}

	// 	//  Get file params (support both $request and raw $_FILES)
	// 	$files = $request->get_file_params();
	// 	if ( empty( $files ) || empty( $files['file'] ) ) {
	// 		if ( ! empty( $_FILES['file'] ) ) {
	// 			$files = $_FILES;
	// 		}
	// 	}

	// 	if ( empty( $files ) || empty( $files['file'] ) ) {
	// 		return new WP_Error( 'missing_file', 'No file provided', array( 'status' => 400 ) );
	// 	}

	// 	$file = $files['file'];

	// 	//  Ensure upload didn’t error out
	// 	if ( ! empty( $file['error'] ) && $file['error'] !== UPLOAD_ERR_OK ) {
	// 		return new WP_Error( 'upload_error', 'Upload failed with error code: ' . $file['error'], array( 'status' => 400 ) );
	// 	}

	// 	//  MIME validation
	// 	$allowed = array( 'image/png', 'image/jpeg', 'application/pdf', 'text/plain' );
	// 	$finfo   = finfo_open( FILEINFO_MIME_TYPE );
	// 	$mime    = finfo_file( $finfo, $file['tmp_name'] );
	// 	finfo_close( $finfo );

	// 	if ( ! in_array( $mime, $allowed, true ) ) {
	// 		return new WP_Error( 'invalid_mime', 'Invalid file type: ' . $mime, array( 'status' => 400 ) );
	// 	}

	// 	//  Size validation (max 10MB)
	// 	if ( $file['size'] > 10 * 1024 * 1024 ) {
	// 		return new WP_Error( 'file_too_large', 'File too large (max 10MB allowed)', array( 'status' => 400 ) );
	// 	}

	// 	try {
	// 		$drive_file = new Google_Service_Drive_DriveFile();
	// 		$drive_file->setName( sanitize_file_name( $file['name'] ) );

	// 		$result = $this->drive_service->files->create(
	// 			$drive_file,
	// 			array(
	// 				'data'       => file_get_contents( $file['tmp_name'] ),
	// 				'mimeType'   => $mime,
	// 				'uploadType' => 'multipart',
	// 				'fields'     => 'id,name,mimeType,size,webViewLink',
	// 			)
	// 		);

	// 		return new WP_REST_Response( array(
	// 			'success' => true,
	// 			'file'    => array(
	// 				'id'          => $result->getId(),
	// 				'name'        => $result->getName(),
	// 				'mimeType'    => $result->getMimeType(),
	// 				'size'        => $result->getSize(),
	// 				'webViewLink' => $result->getWebViewLink(),
	// 			),
	// 		) );
	// 	} catch ( Exception $e ) {
	// 		return new WP_Error( 'upload_failed', $e->getMessage(), array( 'status' => 500 ) );
	// 	}
	// }
	public function upload_file( WP_REST_Request $request ) {
		$perm = $this->rest_permission_authenticated();
		if ( is_wp_error( $perm ) ) {
			return $perm;
		}

		$files = $request->get_file_params();
		if ( empty( $files['file'] ) ) {
			return new WP_Error( 'missing_file', 'No file provided', array( 'status' => 400 ) );
		}

		$file = $files['file'];

		// Upload error check
		if ( isset( $file['error'] ) && $file['error'] !== UPLOAD_ERR_OK ) {
			return new WP_Error( 'upload_error', 'Upload failed with error code: ' . $file['error'], array( 'status' => 400 ) );
		}

		if ( empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			return new WP_Error( 'invalid_upload', 'Uploaded file is invalid', array( 'status' => 400 ) );
		}

		// Size validation (10MB max)
		if ( $file['size'] > 10 * 1024 * 1024 ) {
			return new WP_Error( 'file_too_large', 'File too large (max 10MB allowed)', array( 'status' => 400 ) );
		}

		// Safe filename
		$safe_name = sanitize_file_name( $file['name'] );

		// Validate MIME
		$finfo = finfo_open( FILEINFO_MIME_TYPE );
		$mime  = finfo_file( $finfo, $file['tmp_name'] );
		finfo_close( $finfo );

		$allowed = array( 'image/png', 'image/jpeg', 'application/pdf', 'text/plain', 'application/json', 'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' );
		if ( ! in_array( $mime, $allowed, true ) ) {
			return new WP_Error( 'invalid_mime', 'Invalid file type: ' . $mime, array( 'status' => 400 ) );
		}

		try {
			$drive_file = new Google_Service_Drive_DriveFile();
			$drive_file->setName( $safe_name );

			// ✅ Use file_get_contents (works reliably with multipart uploads)
			$result = $this->drive_service->files->create(
				$drive_file,
				array(
					'data'       => file_get_contents( $file['tmp_name'] ),
					'mimeType'   => $mime,
					'uploadType' => 'multipart',
					'fields'     => 'id,name,mimeType,size,webViewLink',
				)
			);

			return new WP_REST_Response( array(
				'success' => true,
				'file'    => array(
					'id'          => $result->getId(),
					'name'        => $result->getName(),
					'mimeType'    => $result->getMimeType(),
					'size'        => $result->getSize(),
					'webViewLink' => $result->getWebViewLink(),
				),
			) );
		} catch ( \Exception $e ) {
			return new WP_Error( 'upload_failed', $e->getMessage(), array( 'status' => 500 ) );
		}
	}



    public function download_file( WP_REST_Request $request ) {
		$perm = $this->rest_permission_authenticated();
		if ( is_wp_error( $perm ) ) {
			return $perm;
		}

        $file_id = sanitize_text_field( (string) $request->get_param( 'file_id' ) );
        if ( empty( $file_id ) ) {
            return new WP_Error( 'missing_file_id', 'file_id is required', array( 'status' => 400 ) );
        }

        try {
            $f = $this->drive_service->files->get( $file_id, array( 'fields' => 'id,name,mimeType,size,webViewLink,webContentLink' ) );
            return new WP_REST_Response( array(
                'success' => true,
                'file' => array(
                    'id' => $f->getId(),
                    'name' => $f->getName(),
                    'mimeType' => $f->getMimeType(),
                    'size' => $f->getSize(),
                    'webViewLink' => $f->getWebViewLink(),
                    'webContentLink' => method_exists( $f, 'getWebContentLink' ) ? $f->getWebContentLink() : '',
                ),
            ) );
        } catch ( \Exception $e ) {
            return new WP_Error( 'download_failed', $e->getMessage(), array( 'status' => 500 ) );
        }
    }

    public function create_folder( WP_REST_Request $request ) {
		$perm = $this->rest_permission_authenticated();
		if ( is_wp_error( $perm ) ) {
			return $perm;
		}

        $body = $request->get_json_params();
        $name = isset( $body['name'] ) ? sanitize_text_field( $body['name'] ) : '';

        if ( empty( $name ) ) {
            return new WP_Error( 'missing_name', 'Folder name is required', array( 'status' => 400 ) );
        }
		// Length validation
		if ( strlen( $name ) > 150 ) {
			return new WP_Error( 'name_too_long', 'Folder name too long (max 150 characters)', array( 'status' => 400 ) );
		}

		// Regex validation (only letters, numbers, spaces, dash, underscore, dot)
		if ( ! preg_match( '/^[A-Za-z0-9 _.\-]+$/', $name ) ) {
			return new WP_Error(
				'invalid_name',
				'Invalid folder name. Only letters, numbers, spaces, underscores, dashes, and dots are allowed.',
				array( 'status' => 400 )
			);
		}
        try {
            $folder = new Google_Service_Drive_DriveFile();
            $folder->setName( sanitize_text_field( $name ) );
            $folder->setMimeType( 'application/vnd.google-apps.folder' );

            $result = $this->drive_service->files->create( $folder, array(
                'fields' => 'id,name,mimeType,webViewLink',
            ) );

            return new WP_REST_Response( array(
                'success' => true,
                'folder'  => array(
                    'id'          => $result->getId(),
                    'name'        => $result->getName(),
                    'mimeType'    => $result->getMimeType(),
                    'webViewLink' => $result->getWebViewLink(),
                ),
            ) );
        } catch ( \Exception $e ) {
            return new WP_Error( 'create_failed', $e->getMessage(), array( 'status' => 500 ) );
        }
    }
}

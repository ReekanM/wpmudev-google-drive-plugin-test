/* Updated: implemented all UI actions: save credentials, auth, file list, upload, download, create folder. */

import { createRoot, render, StrictMode, useState, useEffect, createInterpolateElement } from '@wordpress/element';
import { Button, TextControl, Spinner, Notice } from '@wordpress/components';

import "./scss/style.scss"

const domElement = document.getElementById(window.wpmudevDriveTest.dom_element_id);

const WPMUDEV_DriveTest = () => {
    const [isAuthenticated, setIsAuthenticated] = useState(window.wpmudevDriveTest.authStatus || false);
    const [hasCredentials, setHasCredentials] = useState(window.wpmudevDriveTest.hasCredentials || false);
    const [showCredentials, setShowCredentials] = useState(!window.wpmudevDriveTest.hasCredentials);
    const [isLoading, setIsLoading] = useState(false);
    const [files, setFiles] = useState([]);
    const [nextPageToken, setNextPageToken] = useState(null);
    const [uploadFile, setUploadFile] = useState(null);
    const [folderName, setFolderName] = useState('');
    const [notice, setNotice] = useState({ message: '', type: '' });
    const [credentials, setCredentials] = useState({
        clientId: '',
        clientSecret: ''
    });

    // Handle OAuth success query param
    useEffect(() => {
        const params = new URLSearchParams(window.location.search);
        if (params.get('auth') === 'success') {
            setIsAuthenticated(true);
            setShowCredentials(false);
            showNotice('Google Drive authentication successful', 'success');

            // Remove query param
            const url = new URL(window.location);
            url.searchParams.delete('auth');
            window.history.replaceState({}, '', url);
        }
    }, []);

    useEffect(() => {
        if (isAuthenticated) loadFiles();
    }, [isAuthenticated]);

    const showNotice = (message, type = 'success') => {
        setNotice({ message, type });
        setTimeout(() => setNotice({ message: '', type: '' }), 5000);
    };

    const apiFetch = async (path, opts = {}) => {
        const headers = Object.assign({}, opts.headers || {}, { 'X-WP-Nonce': window.wpmudevDriveTest.nonce });
        const res = await fetch(path, Object.assign({}, opts, { headers }));
        const json = await res.json().catch(() => ({}));
        if (!res.ok) throw new Error(json.message || 'Request failed');
        return json;
    };

    const handleSaveCredentials = async () => {
        if (!credentials.clientId.trim() || !credentials.clientSecret.trim()) {
            showNotice('Client ID and Client Secret are required', 'error');
            return;
        }
        setIsLoading(true);
        try {
            await apiFetch('/stockroom/wp-json/wpmudev/v1/drive/save-credentials', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ client_id: credentials.clientId.trim(), client_secret: credentials.clientSecret.trim() }),
            });
            setHasCredentials(true);
            setShowCredentials(false);
            showNotice('Credentials saved', 'success');
        } catch (e) {
            showNotice(e.message || 'Failed to save credentials', 'error');
        } finally {
            setIsLoading(false);
        }
    };

    const handleAuth = async () => {
        setIsLoading(true);
        try {
            const res = await apiFetch('/stockroom/wp-json/wpmudev/v1/drive/auth', { method: 'POST' });
            if (res && res.url) {
                window.location = res.url;
                return;
            }
            showNotice('Could not start OAuth flow', 'error');
        } catch (e) {
            showNotice(e.message || 'Auth failed', 'error');
        } finally {
            setIsLoading(false);
        }
    };

    const loadFiles = async (pageSize = 20, pageToken = '', append = false) => {
        setIsLoading(true);
        try {
            const qs = new URLSearchParams({ page_size: pageSize });
            if (pageToken) qs.append('page_token', pageToken);

            const res = await apiFetch('/stockroom/wp-json/wpmudev/v1/drive/files?' + qs.toString(), { method: 'GET' });

            if (append) {
                setFiles(prev => [...prev, ...(res.files || [])]);
            } else {
                setFiles(res.files || []);
            }

            setNextPageToken(res.nextPageToken || null);
        } catch (e) {
            showNotice(e.message || 'Unable to load files', 'error');
        } finally {
            setIsLoading(false);
        }
    };

    const handleUpload = async () => {
        if (!uploadFile) {
            showNotice('No file chosen', 'warning');
            return;
        }

        // Size validation again here (in case user bypassed input check)
        if (uploadFile.size > 10 * 1024 * 1024) {
            showNotice("File too large (max 10MB allowed)", "error");
            return;
        }

        try {
            await new Promise((resolve, reject) => {
                const xhr = new XMLHttpRequest();
                xhr.open("POST", "/stockroom/wp-json/wpmudev/v1/drive/upload", true);
                xhr.setRequestHeader("X-WP-Nonce", window.wpmudevDriveTest.nonce);

                xhr.upload.onprogress = (e) => {
                    if (e.lengthComputable) {
                        const percent = Math.round((e.loaded / e.total) * 100);
                        setNotice({ message: `Uploading... ${percent}%`, type: "info" });
                    }
                };

                xhr.onload = () => {
                    if (xhr.status >= 200 && xhr.status < 300) {
                        resolve();
                    } else {
                        reject(new Error(xhr.statusText || "Upload failed"));
                    }
                };

                xhr.onerror = () => reject(new Error("Upload failed"));
                const fd = new FormData();
                fd.append("file", uploadFile);
                xhr.send(fd);
            });

            showNotice("Upload successful", "success");
            setUploadFile(null);
            loadFiles(); // auto refresh
        } catch (e) {
            showNotice(e.message || "Upload failed", "error");
        } finally {
            setIsLoading(false);
        }
    };


    const handleDownload = async (fileId, fileName) => {
        try {
            const res = await fetch('/stockroom/wp-json/wpmudev/v1/drive/download?file_id=' + encodeURIComponent(fileId), {
                headers: { 'X-WP-Nonce': window.wpmudevDriveTest.nonce },
            });
            if (!res.ok) throw new Error('Download failed');
            const blob = await res.blob();
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = fileName;
            document.body.appendChild(a);
            a.click();
            a.remove();
            window.URL.revokeObjectURL(url);
        } catch (e) {
            showNotice(e.message, 'error');
        }
    };

    const handleCreateFolder = async () => {
        if (!folderName.trim()) {
            showNotice('Folder name required', 'warning');
            return;
        }
        if (folderName.trim().length > 150) {
            showNotice('Folder name too long (max 150 characters)', 'warning');
            return;
        }
        // Regex validation
        const validNameRegex = /^[a-zA-Z0-9 _\-\(\)]+$/;
        if (!validNameRegex.test(folderName.trim())) {
            showNotice('Invalid folder name. Only letters, numbers, spaces, dashes, underscores, and parentheses are allowed.', 'error');
            return;
        }
        setIsLoading(true);
        try {
            await apiFetch('/stockroom/wp-json/wpmudev/v1/drive/create', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ name: folderName.trim() }),
            });
            showNotice('Folder created', 'success');
            setFolderName('');
            loadFiles();
        } catch (e) {
            showNotice(e.message || 'Create folder failed', 'error');
        } finally {
            setIsLoading(false);
        }
    };

    return (
        <>
            <div className="sui-header" style={{ flexDirection: 'column' }}>
                <h1 className="sui-header-title">Google Drive Test</h1>
                <p className="sui-description">Test Google Drive API integration for applicant assessment</p>
            </div>

            {notice.message && (
                <Notice status={notice.type} isDismissible onRemove=''>
                    {notice.message}
                </Notice>
            )}

            {showCredentials ? (
                <div className="sui-box">
                    <div className="sui-box-header">
                        <h2 className="sui-box-title">Set Google Drive Credentials</h2>
                    </div>
                    <div className="sui-box-body">
                        <div className="sui-box-settings-row">
                            <TextControl
                                help={createInterpolateElement(
                                    'You can get Client ID from <a>Google Cloud Console</a>. Make sure to enable Google Drive API.',
                                    { a: <a href="https://console.cloud.google.com/apis/credentials" target="_blank" rel="noopener noreferrer" /> }
                                )}
                                label="Client ID"
                                value={credentials.clientId}
                                onChange={(value) => setCredentials({ ...credentials, clientId: value })}
                            />
                        </div>
                        <div className="sui-box-settings-row">
                            <TextControl
                                help={createInterpolateElement(
                                    'You can get Client Secret from <a>Google Cloud Console</a>.',
                                    { a: <a href="https://console.cloud.google.com/apis/credentials" target="_blank" rel="noopener noreferrer" /> }
                                )}
                                label="Client Secret"
                                value={credentials.clientSecret}
                                onChange={(value) => setCredentials({ ...credentials, clientSecret: value })}
                                type="password"
                            />
                        </div>
                        <div className="sui-box-settings-row">
                            <span>Please use this URL <em>{window.wpmudevDriveTest.redirectUri}</em> in your Google API's <strong>Authorized redirect URIs</strong> field.</span>
                        </div>
                        <div className="sui-box-settings-row" style={{ flexDirection: 'column' }}>
                            <p><strong>Required scopes for Google Drive API:</strong></p>
                            <ul>
                                <li>https://www.googleapis.com/auth/drive.file</li>
                                <li>https://www.googleapis.com/auth/drive.readonly</li>
                            </ul>
                        </div>
                    </div>
                    <div className="sui-box-footer">
                        <div className="sui-actions-right">
                            <Button variant="primary" onClick={handleSaveCredentials} disabled={isLoading}>
                                {isLoading ? <Spinner /> : 'Save Credentials'}
                            </Button>
                        </div>
                    </div>
                </div>
            ) : !isAuthenticated ? (
                <div className="sui-box">
                    <div className="sui-box-header">
                        <h2 className="sui-box-title">Authenticate with Google Drive</h2>
                    </div>
                    <div className="sui-box-body">
                        <p>Please authenticate with Google Drive to proceed with the test.</p>
                        <p><strong>This test will require the following permissions:</strong></p>
                        <ul>
                            <li>View and manage Google Drive files</li>
                            <li>Upload new files to Drive</li>
                            <li>Create folders in Drive</li>
                        </ul>
                    </div>
                    <div className="sui-box-footer">
                        <div className="sui-actions-left">
                            <Button variant="secondary" onClick={() => setShowCredentials(true)}>Change Credentials</Button>
                        </div>
                        <div className="sui-actions-right">
                            <Button variant="primary" onClick={handleAuth} disabled={isLoading}>
                                {isLoading ? <Spinner /> : 'Authenticate with Google Drive'}
                            </Button>
                        </div>
                    </div>
                </div>
            ) : (
                <>
                    <div className="sui-box">
                        <div className="sui-box-header">
                            <h2 className="sui-box-title">Upload File to Drive</h2>
                        </div>
                        <div className="sui-box-body">
                            <input
                                type="file"
                                onChange={(e) => {
                                    const file = e.target.files[0];
                                    if (file && file.size > 10 * 1024 * 1024) {
                                        showNotice("File too large (max 10MB allowed)", "error");
                                        return;
                                    }
                                    setUploadFile(file);
                                }}
                            />
                            {uploadFile && (
                                <p>
                                    <strong>Selected:</strong> {uploadFile.name} ({Math.round(uploadFile.size / 1024)} KB)
                                </p>
                            )}
                        </div>
                        <div className="sui-box-footer">
                            <div className="sui-actions-right">
                                <Button
                                    variant="primary"
                                    onClick={handleUpload}
                                    disabled={isLoading || !uploadFile}
                                >
                                    {isLoading ? <Spinner /> : 'Upload to Drive'}
                                </Button>
                            </div>
                        </div>
                    </div>

                    {/* Create Folder */}
                    <div className="sui-box">
                        <div className="sui-box-header">
                            <h2 className="sui-box-title">Create New Folder</h2>
                        </div>
                        <div className="sui-box-body">
                            <TextControl label="Folder Name" value={folderName} onChange={setFolderName} placeholder="Enter folder name" />
                        </div>
                          <div className="sui-box-footer">
                            <div className="sui-actions-right">
                                <Button
                                    variant="secondary"
                                    onClick={handleCreateFolder}
                                    disabled={isLoading || !folderName.trim()}
                                >
                                    {isLoading ? <Spinner /> : 'Create Folder'}
                                </Button>
                            </div>
                        </div>
                    </div>

                    {/* Files List */}
                    <div className="sui-box">
                        <div className="sui-box-header">
                            <h2 className="sui-box-title">Your Drive Files</h2>
                            <div className="sui-actions-right">
                                <Button
                                    variant="secondary"
                                    onClick={loadFiles}
                                    disabled={isLoading}
                                >
                                    {isLoading ? <Spinner /> : 'Refresh Files'}
                                </Button>
                            </div>
                        </div>
                        <div className="sui-box-body">
                            {isLoading ? (
                                <div className="drive-loading"><Spinner /><p>Loading files...</p></div>
                            ) : files.length > 0 ? (
                                <>
                                <div className="drive-files-grid">
                                    {files.map(file => (
                                        <div key={file.id} className="drive-file-item">
                                            <div className="file-info">
                                                <strong>{file.name}</strong>
                                                <small>
                                                    {file.mimeType === 'application/vnd.google-apps.folder' ? 'Folder' : 'File'} • 
                                                    {file.mimeType !== 'application/vnd.google-apps.folder' ? `${Math.round(file.size/1024)} KB` : ''} •
                                                    {file.modifiedTime ? new Date(file.modifiedTime).toLocaleDateString() : 'Unknown date'}
                                                </small>
                                            </div>
                                            <div className="file-actions">
                                                {file.webViewLink && (
                                                    <Button variant="link" size="small" href={file.webViewLink} target="_blank" rel="noopener noreferrer">
                                                        View in Drive
                                                    </Button>
                                                )}
                                                {file.mimeType !== 'application/vnd.google-apps.folder' && (
                                                    <Button variant="secondary" size="small" onClick={() => handleDownload(file.id, file.name)}>Download</Button>
                                                )}
                                            </div>
                                        </div>
                                    ))}
                                </div>
                                {/* Load More button  */}
                                {nextPageToken && (
                                    <div className="sui-actions-right" style={{ marginTop: '1rem' }}>
                                        <Button
                                            variant="secondary"
                                            onClick={() => loadFiles(20, nextPageToken, true)}
                                            disabled={isLoading}
                                        >
                                            {isLoading ? <Spinner /> : 'Load More'}
                                        </Button>
                                    </div>
                                )}
                                </>
                            ) : (
                                <p>No files found in your Drive. Upload a file or create a folder to get started.</p>
                            )}
                        </div>
                    </div>
                </>
            )}
        </>
    );
}

if (createRoot) {
    createRoot(domElement).render(<StrictMode><WPMUDEV_DriveTest /></StrictMode>);
} else {
    render(<StrictMode><WPMUDEV_DriveTest /></StrictMode>, domElement);
}

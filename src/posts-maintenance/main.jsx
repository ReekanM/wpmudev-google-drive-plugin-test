import { createRoot } from '@wordpress/element';
import { useState, useEffect } from '@wordpress/element';
import { Button, Spinner, CheckboxControl, Notice, PanelBody, PanelRow } from '@wordpress/components';

const rootEl = document.getElementById( 'wpmudev-posts-maintenance-app' );

function PostsMaintenanceApp() {
    const available = window.wpmudevPostsMaintenance && window.wpmudevPostsMaintenance.availablePostTypes ? window.wpmudevPostsMaintenance.availablePostTypes : {};
    const defaultBatch = (window.wpmudevPostsMaintenance && window.wpmudevPostsMaintenance.defaultBatchSize) || 50;

    const initialTypes = Object.keys(available).length ? Object.keys(available).filter(k => ['post','page'].includes(k)) : ['post','page'];

    const [selectedTypes, setSelectedTypes] = useState(initialTypes);
    const [isScanning, setIsScanning] = useState(false);
    const [scanId, setScanId] = useState(null);
    const [status, setStatus] = useState(null);
    const [batchSize, setBatchSize] = useState(defaultBatch);
    const [notice, setNotice] = useState(null);
    const [history, setHistory] = useState([]);

    useEffect(() => {
        fetchHistory();
    }, []);

    useEffect(() => {
        let interval = null;
        if ( scanId ) {
            setIsScanning(true);
            interval = setInterval(async () => {
                await pollStatus(scanId);
            }, 1500);
        } else {
            setIsScanning(false);
            setStatus(null);
        }
        return () => {
            if ( interval ) clearInterval(interval);
        };
    }, [scanId]);

    const fetchHistory = async () => {
        try {
            const res = await fetch(window.wpmudevPostsMaintenance.root + 'wpmudev/v1/posts-scan/list', {
                headers: { 'X-WP-Nonce': window.wpmudevPostsMaintenance.nonce }
            });
            const json = await res.json();
            if (res.ok && json.history) {
                setHistory(json.history || []);
            }
        } catch(e) {
            console.warn(e);
        }
    };

    const pollStatus = async (id) => {
        try {
            const url = new URL(window.wpmudevPostsMaintenance.root + 'wpmudev/v1/posts-scan/status');
            url.searchParams.set('scan_id', id);
            const res = await fetch( url.toString(), {
                headers: { 'X-WP-Nonce': window.wpmudevPostsMaintenance.nonce }
            } );
            if (!res.ok) {
                // stop polling on error
                setNotice({status:'error',message:'Failed to fetch scan status'});
                setScanId(null);
                return;
            }
            const json = await res.json();
            setStatus(json);
            if ( json.status === 'completed' || json.status === 'failed' ) {
                setScanId(null); // stop polling
                setNotice({ status: json.status === 'completed' ? 'success' : 'error', message: json.message || 'Scan finished' });
                fetchHistory();
            }
        } catch(e) {
            console.error(e);
            setNotice({status:'error',message:'Unexpected error while polling status'});
            setScanId(null);
        }
    };

    const handleToggleType = (slug) => {
        setSelectedTypes(prev => {
            if ( prev.includes(slug) ) return prev.filter(s => s !== slug);
            return [...prev, slug];
        });
    };

    const handleStart = async () => {
        if ( selectedTypes.length === 0 ) {
            setNotice({status: 'error', message: 'Select at least one post type.'});
            return;
        }
        setNotice(null);
        try {
            const res = await fetch(window.wpmudevPostsMaintenance.root + 'wpmudev/v1/posts-scan/start', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': window.wpmudevPostsMaintenance.nonce
                },
                body: JSON.stringify({ post_types: selectedTypes, batch_size: batchSize })
            });
            const json = await res.json();
            if (!res.ok) {
                setNotice({status:'error', message: json.message || 'Failed to start scan'});
                return;
            }
            setScanId(json.scan_id);
            setStatus({ processed: 0, total: 0, status: 'queued' });
            setNotice({status:'info', message:'Scan started'});
        } catch(e) {
            setNotice({status:'error', message:'Unexpected error starting scan'});
        }
    };

    const percent = status && status.total ? Math.round( (status.processed / status.total) * 100 ) : 0;

    return (
        <div className="wpmudev-posts-maintenance">
            { notice && <Notice status={notice.status} isDismissible>{ notice.message }</Notice> }

            <PanelBody title="Scan Settings" initialOpen={true}>
                <PanelRow>
                    <div style={{ width: '100%' }}>
                        <p><strong>Select post types to scan</strong></p>
                        { Object.keys(available).map(slug => (
                            <CheckboxControl
                                key={slug}
                                label={available[slug]}
                                checked={selectedTypes.includes(slug)}
                                onChange={() => handleToggleType(slug)}
                            />
                        )) }
                    </div>
                </PanelRow>

                <PanelRow>
                    <div>
                        <label><strong>Batch size</strong> (posts per background job)</label>
                        <input type="number" min="1" max="500" value={batchSize} onChange={(e)=>setBatchSize(Math.max(1, Math.min(500, parseInt(e.target.value||defaultBatch)) ))} />
                    </div>
                </PanelRow>

                <PanelRow>
                    <Button isPrimary onClick={handleStart} disabled={isScanning}>
                        { isScanning ? <><Spinner /> Processing…</> : 'Scan Posts' }
                    </Button>
                </PanelRow>
            </PanelBody>

            <div style={{ marginTop: 16 }}>
                <h2>Progress</h2>
                { status ? (
                    <div>
                        <p>Status: <strong>{ status.status }</strong></p>
                        <p>Processed: { status.processed } / { status.total }</p>
                        <div style={{ width: '100%', background: '#eee', height: 12, borderRadius: 4 }}>
                            <div style={{ width: `${percent}%`, height: '100%', background: '#3b82f6', borderRadius: 4 }} />
                        </div>
                    </div>
                ) : (
                    <p>No active scan running.</p>
                ) }
            </div>

            <div style={{ marginTop: 16 }}>
                <h2>Recent Scans</h2>
                { history.length ? (
                    <ul>
                        { history.map(h => (
                            <li key={h.id}>
                                { h.started_at } — { h.total } posts — { h.post_types.join(', ') } — ID: { h.id }
                            </li>
                        )) }
                    </ul>
                ) : <p>No previous scans recorded.</p> }
            </div>
        </div>
    );
}

// mount to DOM
if ( rootEl ) {
    createRoot( rootEl ).render( <PostsMaintenanceApp /> );
}

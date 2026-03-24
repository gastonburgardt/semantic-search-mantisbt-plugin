(function(){
	const btn = document.getElementById('start_reindex_btn');
	const processBtn = document.getElementById('process_state_btn');
	const statusEl = document.getElementById('reindex_status');
	const policyInfoEl = document.getElementById('policy_info');
	const vectorInfoEl = document.getElementById('vector_info');
	const stopBtn = document.getElementById('stop_reindex_btn');
	const baseHolder = document.getElementById('reindex_action_base');
	const tokenHolder = document.getElementById('reindex_form_token');
	if(!btn || !processBtn || !statusEl || !policyInfoEl || !vectorInfoEl || !baseHolder || !tokenHolder || !stopBtn) return;

	const base = baseHolder.value;
	const formToken = tokenHolder.value;
	let pollTimer = null;
	let currentRun = null;

	function v(id){ return (document.getElementById(id)?.value || '').trim(); }
	function selectedMode(){ return (document.querySelector('input[name="vector_mode"]:checked') || {}).value || 'pending'; }
	function q(params){ return new URLSearchParams(params).toString(); }
	async function getJson(params){ const r = await fetch(base + '&' + q(params), { credentials:'same-origin' }); return r.json(); }
	function buildFilters(projectId){
		const mode = selectedMode();
		return {
			ajax: 1,
			form_security_token: formToken,
			project_id: projectId,
			issue_id: v('issue_id'),
			created_from: v('created_from'),
			created_to: v('created_to'),
			max_issues: v('max_issues'),
			pending_only: mode === 'pending' ? 1 : 0,
			force_revectorize: mode === 'force' ? 1 : 0,
		};
	}
	function setRunning(running){
		processBtn.disabled = !!running;
		btn.disabled = !!running;
		stopBtn.disabled = !running;
	}
	function clearPoll(){ if(pollTimer){ clearInterval(pollTimer); pollTimer = null; } }
	function renderRun(run, kind){
		if(!run){ return; }
		const total = parseInt(run.Total || 0, 10);
		const processed = parseInt(run.Processed || 0, 10);
		const ok = parseInt(run.OkCount || 0, 10);
		const skip = parseInt(run.SkipCount || 0, 10);
		const fail = parseInt(run.FailCount || 0, 10);
		const status = run.Status || 'unknown';
		const msg = run.Message || '';
		const baseText = `${kind === 'policy' ? 'Política' : 'Vectorización'} [${status}] ${processed}/${total} · ok:${ok} skip:${skip} fail:${fail}`;
		statusEl.textContent = msg ? `${baseText} · ${msg}` : baseText;
		if(kind === 'policy'){
			policyInfoEl.textContent = statusEl.textContent;
		}else{
			vectorInfoEl.textContent = statusEl.textContent;
		}
		if(status !== 'running'){
			clearPoll();
			setRunning(false);
			currentRun = null;
		}
	}
	function startPoll(kind, runId, filters){
		clearPoll();
		currentRun = { kind, runId, filters };
		pollTimer = setInterval(async () => {
			if(!currentRun) return;
			try {
				const st = await getJson({ ...filters, mode:'status', run_id: runId });
				if(st.ok){ renderRun(st.run, kind); }
			} catch(e){
				statusEl.textContent = 'Error polling: ' + e.message;
			}
		}, 2000);
	}

	setRunning(false);

	stopBtn.addEventListener('click', async () => {
		if(!currentRun || !currentRun.runId){
			statusEl.textContent = 'No hay run activo.';
			return;
		}
		try {
			const rs = await getJson({ ajax:1, form_security_token:formToken, mode:'stop', run_id:currentRun.runId });
			if(rs.ok){ statusEl.textContent = 'Solicitud de detención enviada.'; }
		} catch(e){ statusEl.textContent = 'Error al detener: ' + e.message; }
	});

	window.addEventListener('beforeunload', async () => {
		if(currentRun && currentRun.runId){
			try { await getJson({ ajax:1, form_security_token:formToken, mode:'stop', run_id:currentRun.runId }); } catch(e){}
		}
	});

	processBtn.addEventListener('click', async () => {
		const projectId = v('project_id');
		const issueId = v('issue_id');
		if(projectId === '' && issueId === ''){ statusEl.textContent = 'Debés indicar Proyecto o Issue ID.'; return; }
		const filters = buildFilters(projectId);
		const batchSize = Math.max(1, parseInt(v('batch_size') || '25', 10));
		setRunning(true);
		policyInfoEl.textContent = 'Política: iniciando en background...';
		try {
			const resp = await getJson({ ...filters, mode:'start_policy', batch_size: batchSize });
			if(!resp.ok){ statusEl.textContent = 'Error: ' + (resp.error || 'no se pudo iniciar'); setRunning(false); return; }
			startPoll('policy', resp.run_id, filters);
		} catch(e){ statusEl.textContent = 'Error: ' + e.message; setRunning(false); }
	});

	btn.addEventListener('click', async () => {
		const projectId = v('project_id');
		const issueId = v('issue_id');
		if(projectId === '' && issueId === ''){ statusEl.textContent = 'Debés indicar Proyecto o Issue ID.'; return; }
		const filters = buildFilters(projectId);
		const batchSize = Math.max(1, parseInt(v('batch_size') || '25', 10));
		setRunning(true);
		vectorInfoEl.textContent = 'Vectorización: iniciando en background...';
		try {
			const resp = await getJson({ ...filters, mode:'start_vector', batch_size: batchSize });
			if(!resp.ok){ statusEl.textContent = 'Error: ' + (resp.error || 'no se pudo iniciar'); setRunning(false); return; }
			startPoll('vectorize', resp.run_id, filters);
		} catch(e){ statusEl.textContent = 'Error: ' + e.message; setRunning(false); }
	});
})();

(function(){
	const btn = document.getElementById('start_reindex_btn');
	const processBtn = document.getElementById('process_state_btn');
	const refreshBtn = document.getElementById('refresh_status_btn');
	const statusEl = document.getElementById('reindex_status');
	const policyInfoEl = document.getElementById('policy_info');
	const vectorInfoEl = document.getElementById('vector_info');
	const stopBtn = document.getElementById('stop_reindex_btn');
	const baseHolder = document.getElementById('reindex_action_base');
	const tokenHolder = document.getElementById('reindex_form_token');
	if(!btn || !processBtn || !refreshBtn || !statusEl || !policyInfoEl || !vectorInfoEl || !baseHolder || !tokenHolder || !stopBtn) return;

	const base = baseHolder.value;
	const formToken = tokenHolder.value;
	let pollTimer = null;
	let currentRun = null;

	function v(id){ return (document.getElementById(id)?.value || '').trim(); }
	function selectedMode(){ return (document.querySelector('input[name="vector_mode"]:checked') || {}).value || 'pending'; }
	function q(params){ return new URLSearchParams(params).toString(); }
	async function getJson(params){ const r = await fetch(base + '&' + q(params), { credentials:'same-origin' }); return r.json(); }
	function setStatus(msg, type='info'){
		statusEl.textContent = msg;
		statusEl.className = 'alert alert-' + (type || 'info');
	}
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
			heartbeat_timeout: 120,
			stall_confirm_seconds: 120,
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
		const baseText = `${kind === 'policy' ? 'Política' : 'Vectorización'} [${status}] ${processed}/${total} · ok:${ok} · omitidos:${skip} · fallos:${fail}`;
		const type = (status === 'failed' || status === 'stale') ? 'danger' : (status === 'running' ? 'warning' : (status === 'stopped' ? 'warning' : 'success'));
		setStatus(msg ? `${baseText} · ${msg}` : baseText, type);
		if(kind === 'policy'){
			policyInfoEl.innerHTML = `<strong>Política:</strong> ${baseText}`;
		}else{
			vectorInfoEl.innerHTML = `<strong>Vectorización:</strong> ${baseText}`;
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
				if(st.ok && st.run){ renderRun(st.run, kind); }
			} catch(e){
				setStatus('Error consultando estado: ' + e.message, 'danger');
			}
		}, 2000);
	}

	async function maybeForceUnlockAndRetry(resp, filters, mode, kind){
		if(!resp || !resp.confirm_restart) return null;
		const stalled = parseInt(resp.stalled_seconds || 0, 10);
		const minStalled = parseInt(resp.stall_confirm_seconds || 0, 10);
		const runId = resp.run_id || '(sin run_id)';
		const ok = window.confirm(
			`Hay un run bloqueando (${runId}) sin heartbeat hace ${stalled}s (umbral ${minStalled}s).\n\n` +
			`¿Querés forzar desbloqueo y lanzar nuevamente ${kind === 'policy' ? 'la revisión de política' : 'la vectorización'}?`
		);
		if(!ok){
			setStatus('Proceso bloqueado por otro run. No se relanzó.', 'warning');
			return { handled:true, started:false };
		}
		const unlock = await getJson({
			ajax:1,
			form_security_token: formToken,
			mode:'force_unlock',
			scope_type: resp.scope_type || 'all',
			scope_project_id: resp.scope_project_id || 0,
		});
		if(!unlock.ok){
			setStatus('No se pudo desbloquear: ' + (unlock.error || 'error desconocido'), 'danger');
			return { handled:true, started:false };
		}
		const retry = await getJson({ ...filters, mode, batch_size: Math.max(1, parseInt(v('batch_size') || '25', 10)) });
		if(!retry.ok){
			setStatus('Desbloqueado, pero no se pudo relanzar: ' + (retry.error || 'error desconocido'), 'danger');
			return { handled:true, started:false };
		}
		startPoll(kind, retry.run_id, filters);
		setStatus(`Run de ${kind === 'policy' ? 'política' : 'vectorización'} relanzado tras desbloqueo.`, 'warning');
		return { handled:true, started:true };
	}

	async function startRun(mode, kind){
		const projectId = v('project_id');
		const issueId = v('issue_id');
		if(projectId === '' && issueId === ''){ setStatus('Debés indicar Proyecto o Issue ID.', 'danger'); return; }
		const filters = buildFilters(projectId);
		const batchSize = Math.max(1, parseInt(v('batch_size') || '25', 10));
		setRunning(true);
		if(kind === 'policy'){
			policyInfoEl.innerHTML = '<strong>Política:</strong> iniciando en background...';
		}else{
			vectorInfoEl.innerHTML = '<strong>Vectorización:</strong> iniciando en background...';
		}
		try {
			const resp = await getJson({ ...filters, mode, batch_size: batchSize });
			if(!resp.ok){
				const retry = await maybeForceUnlockAndRetry(resp, filters, mode, kind);
				if(!retry || !retry.started){ setRunning(false); }
				if(!retry){ setStatus('Error al iniciar: ' + (resp.error || 'no se pudo iniciar'), 'danger'); }
				return;
			}
			setStatus(`Run de ${kind === 'policy' ? 'política' : 'vectorización'} iniciado.`, 'warning');
			startPoll(kind, resp.run_id, filters);
		} catch(e){
			setStatus('Error: ' + e.message, 'danger');
			setRunning(false);
		}
	}

	setRunning(false);

	refreshBtn.addEventListener('click', async () => {
		if(currentRun && currentRun.runId){
			try {
				const st = await getJson({ ...currentRun.filters, mode:'status', run_id: currentRun.runId });
				if(st.ok && st.run){ renderRun(st.run, currentRun.kind); return; }
			} catch(e){ setStatus('Error al consultar estado: ' + e.message, 'danger'); return; }
		}
		setStatus('No hay run activo para consultar. Iniciá una ejecución.', 'info');
	});

	stopBtn.addEventListener('click', async () => {
		if(!currentRun || !currentRun.runId){
			setStatus('No hay run activo.', 'info');
			return;
		}
		try {
			const rs = await getJson({ ajax:1, form_security_token:formToken, mode:'stop', run_id:currentRun.runId });
			if(rs.ok){ setStatus('Solicitud de detención enviada.', 'warning'); }
		} catch(e){ setStatus('Error al detener: ' + e.message, 'danger'); }
	});

	window.addEventListener('beforeunload', async () => {
		if(currentRun && currentRun.runId){
			try { await getJson({ ajax:1, form_security_token:formToken, mode:'stop', run_id:currentRun.runId }); } catch(e){}
		}
	});

	processBtn.addEventListener('click', async () => startRun('start_policy', 'policy'));
	btn.addEventListener('click', async () => startRun('start_vector', 'vectorize'));
})();

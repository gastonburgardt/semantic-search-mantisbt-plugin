(function(){
	const btn = document.getElementById('start_reindex_btn');
	const processBtn = document.getElementById('process_state_btn');
	const statusEl = document.getElementById('reindex_status');
	const policyInfoEl = document.getElementById('policy_info');
	const vectorInfoEl = document.getElementById('vector_info');
	const baseHolder = document.getElementById('reindex_action_base');
	const tokenHolder = document.getElementById('reindex_form_token');
	if(!btn || !processBtn || !statusEl || !policyInfoEl || !vectorInfoEl || !baseHolder || !tokenHolder) return;

	const base = baseHolder.value;
	const formToken = tokenHolder.value;

	function v(id){ return (document.getElementById(id)?.value || '').trim(); }
	function selectedMode(){
		const el = document.querySelector('input[name="vector_mode"]:checked');
		return el ? el.value : 'pending';
	}
	function q(params){ return new URLSearchParams(params).toString(); }
	async function getJson(params){
		const r = await fetch(base + '&' + q(params), { credentials:'same-origin' });
		return r.json();
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
		};
	}

	processBtn.addEventListener('click', async () => {
		const runId = 'policy_' + Date.now() + '_' + Math.random().toString(36).slice(2,8);
		const projectId = v('project_id');
		const issueId = v('issue_id');
		if(projectId === '' && issueId === ''){
			statusEl.textContent = 'Debés indicar Proyecto o Issue ID.';
			return;
		}
		processBtn.disabled = true;
		btn.disabled = true;
		try {
			const filters = buildFilters(projectId);
			const est = await getJson({ ...filters, mode: 'estimate' });
			if(!est.ok){ statusEl.textContent = 'Error: ' + (est.error || 'estimación fallida'); return; }
			const total = est.total || 0;
			const batchSize = Math.max(1, parseInt(v('batch_size') || '25', 10));
			let processed = 0, lastId = 0, flagged = 0, clean = 0, fail = 0, toIndex = 0, toDelete = 0;
			policyInfoEl.textContent = 'Política: iniciando revisión...';
			if(total === 0){ statusEl.textContent = 'No hay incidencias que cumplan los filtros para revisar política.'; return; }
			while(true){
				const step = await getJson({ ...filters, mode:'policy_batch', run_id: runId, batch_size: batchSize, last_id:lastId, processed });
				if(!step.ok){ statusEl.textContent = 'Error: ' + (step.error || 'policy batch fallido'); break; }
				flagged += step.flagged || 0;
				clean += step.clean || 0;
				fail += step.failed || 0;
				toIndex += step.to_index || 0;
				toDelete += step.to_delete || 0;
				processed += step.seen || 0;
				lastId = step.last_id || lastId;
				statusEl.textContent = `Revisión de política: ${processed}/${total} (fallos: ${fail})`;
				policyInfoEl.textContent = `Política → con pendientes: ${flagged}, sin pendientes: ${clean}, para vectorizar: ${toIndex}, para borrar: ${toDelete}.`;
				if(step.done){ break; }
			}
		} catch(e) {
			statusEl.textContent = 'Error inesperado al revisar política: ' + e.message;
		} finally {
			processBtn.disabled = false;
			btn.disabled = false;
		}
	});

	btn.addEventListener('click', async () => {
		const runId = 'vector_' + Date.now() + '_' + Math.random().toString(36).slice(2,8);
		const projectId = v('project_id');
		const issueId = v('issue_id');
		if(projectId === '' && issueId === ''){
			statusEl.textContent = 'Debés indicar Proyecto o Issue ID.';
			return;
		}

		btn.disabled = true;
		let ok = 0, fail = 0, skip = 0, processed = 0, lastId = 0;
		const filters = buildFilters(projectId);
		const batchSize = Math.max(1, parseInt(v('batch_size') || '25', 10));

		try {
			const est = await getJson({ ...filters, mode: 'estimate' });
			if(!est.ok){ statusEl.textContent = 'Error: ' + (est.error || 'estimación fallida'); btn.disabled = false; return; }
			const total = est.total || 0;
			if(total === 0){ statusEl.textContent = 'No hay incidencias que cumplan los filtros.'; btn.disabled = false; return; }
			vectorInfoEl.textContent = `Vectorización → total candidatos: ${total}, pendientes: ${est.pending_total || 0}, al día: ${est.indexed_current || 0}.`;

			while(true){
				const step = await getJson({ ...filters, mode:'batch', run_id: runId, batch_size: batchSize, last_id:lastId, processed });
				if(!step.ok){ statusEl.textContent = 'Error: ' + (step.error || 'batch fallido'); break; }
				ok += step.indexed || 0;
				skip += step.skipped || 0;
				fail += step.failed || 0;
				processed += step.seen || 0;
				lastId = step.last_id || lastId;
				statusEl.textContent = `Vectorización: ${processed}/${total} (fallos: ${fail})`;
				vectorInfoEl.textContent = `Resultado → vectorizados: ${ok}, omitidos: ${skip}, fallos: ${fail}.`;
				if(step.done){ break; }
			}
		} catch(e) {
			statusEl.textContent = 'Error inesperado en UI: ' + e.message;
		} finally {
			btn.disabled = false;
		}
	});
})();

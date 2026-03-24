(function(){
	const btn = document.getElementById('start_reindex_btn');
	const processBtn = document.getElementById('process_state_btn');
	const statusEl = document.getElementById('reindex_status');
	const bar = document.getElementById('reindex_progress_bar');
	const baseHolder = document.getElementById('reindex_action_base');
	const tokenHolder = document.getElementById('reindex_form_token');
	if(!btn || !processBtn || !statusEl || !bar || !baseHolder || !tokenHolder) {
		return;
	}

	const base = baseHolder.value;
	const formToken = tokenHolder.value;

	function v(id){ return (document.getElementById(id)?.value || '').trim(); }
	function q(params){ return new URLSearchParams(params).toString(); }
	function updateBar(done,total,ok,fail,skip,pending){
		const pct = total > 0 ? Math.min(100, Math.round(done * 100 / total)) : 0;
		bar.style.width = pct + '%';
		bar.textContent = pct + '%';
		const pendingText = typeof pending === 'number' ? `, pendientes: ${pending}` : '';
		statusEl.textContent = `Procesados: ${done}/${total} (vectorizados: ${ok}, omitidos: ${skip}, fallos: ${fail}${pendingText})`;
	}
	async function getJson(params){
		const r = await fetch(base + '&' + q(params), { credentials:'same-origin' });
		return r.json();
	}
	function buildFilters(projectId){
		return {
			ajax: 1,
			form_security_token: formToken,
			project_id: projectId,
			issue_id: v('issue_id'),
			created_from: v('created_from'),
			created_to: v('created_to'),
			max_issues: v('max_issues')
		};
	}

	processBtn.addEventListener('click', async () => {
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
			updateBar(0,total,0,0,0,null);
			if(total === 0){ statusEl.textContent = 'No hay incidencias que cumplan los filtros para revisar política.'; return; }
			while(true){
				const step = await getJson({ ...filters, mode:'policy_batch', batch_size: batchSize, last_id:lastId, processed });
				if(!step.ok){ statusEl.textContent = 'Error: ' + (step.error || 'policy batch fallido'); break; }
				flagged += step.flagged || 0;
				clean += step.clean || 0;
				fail += step.failed || 0;
				toIndex += step.to_index || 0;
				toDelete += step.to_delete || 0;
				processed += step.seen || 0;
				lastId = step.last_id || lastId;
				const pct = total > 0 ? Math.min(100, Math.round(processed * 100 / total)) : 0;
				bar.style.width = pct + '%';
				bar.textContent = pct + '%';
				statusEl.textContent = `Revisión de política: ${processed}/${total} (con cambios pendientes: ${flagged}, para vectorizar: ${toIndex}, para borrar: ${toDelete}, sin pendientes: ${clean}, fallos: ${fail})`;
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
		const projectId = v('project_id');
		const issueId = v('issue_id');
		if(projectId === '' && issueId === ''){
			statusEl.textContent = 'Debés indicar Proyecto o Issue ID.';
			return;
		}

		btn.disabled = true;
		let ok = 0, fail = 0, skip = 0, processed = 0, lastId = 0, pending = null;
		const filters = buildFilters(projectId);
		const batchSize = Math.max(1, parseInt(v('batch_size') || '25', 10));

		try {
			const est = await getJson({ ...filters, mode: 'estimate' });
			if(!est.ok){ statusEl.textContent = 'Error: ' + (est.error || 'estimación fallida'); btn.disabled = false; return; }
			const total = est.total || 0;
			pending = (typeof est.pending_total === 'number') ? est.pending_total : null;
			updateBar(0,total,0,0,0,pending);
			if(total === 0){ statusEl.textContent = 'No hay incidencias que cumplan los filtros.'; btn.disabled = false; return; }
			if(typeof est.indexed_current === 'number'){
				statusEl.textContent = `Estado inicial — total: ${total}, al día: ${est.indexed_current}, pendientes: ${est.pending_total || 0} (nuevos: ${est.pending_new_total || 0}, por modificaciones: ${est.pending_modified_total || 0}, cuerpo: ${est.pending_body || 0}, archivos: ${est.pending_attachments || 0})`;
			}

			while(true){
				const step = await getJson({ ...filters, mode:'batch', batch_size: batchSize, last_id:lastId, processed });
				if(!step.ok){ statusEl.textContent = 'Error: ' + (step.error || 'batch fallido'); break; }
				ok += step.indexed || 0;
				skip += step.skipped || 0;
				fail += step.failed || 0;
				processed += step.seen || 0;
				lastId = step.last_id || lastId;
				if(typeof pending === 'number'){
					pending = Math.max(0, pending - (step.indexed || 0));
				}
				updateBar(processed, total, ok, fail, skip, pending);
				if(step.done){ break; }
			}
		} catch(e) {
			statusEl.textContent = 'Error inesperado en UI: ' + e.message;
		} finally {
			btn.disabled = false;
		}
	});
})();

(() => {
  'use strict';
  const $ = id => document.getElementById(id);
  const form = $('create-form'), kind = $('kind'), parent = $('parent'), submit = $('submit');
  const csrf = document.querySelector('meta[name="csrf-token"]').content;
  let data = null, busy = false, reviewRequired = false;
  const tell = message => { $('notice').textContent = message; };
  function render() {
    const root = $('structure'); root.replaceChildren();
    if (!data.levels.length) { root.textContent = 'No levels configured yet.'; return; }
    const list = document.createElement('ul'); root.append(list);
    for (const level of data.levels) {
      const li = document.createElement('li');
      li.textContent = level.name + (level.is_active ? '' : ' (inactive)'); list.append(li);
      const grades = data.grades.filter(g => g.level_id === level.id);
      if (!grades.length) continue;
      const gl = document.createElement('ul'); li.append(gl);
      for (const grade of grades) {
        const gi = document.createElement('li'); gi.textContent = grade.name + (grade.is_active ? '' : ' (inactive)'); gl.append(gi);
        const sections = data.sections.filter(s => s.grade_id === grade.id);
        if (!sections.length) continue;
        const sl = document.createElement('ul'); gi.append(sl);
        for (const section of sections) { const si = document.createElement('li'); si.textContent = section.name + (section.is_active ? '' : ' (inactive)'); sl.append(si); }
      }
    }
  }
  function controls() {
    const type = kind.value; $('parent-wrap').hidden = type === 'level';
    parent.replaceChildren();
    const activeLevels = data ? data.levels.filter(l => l.is_active) : [];
    const options = type === 'grade' ? activeLevels : type === 'section' && data
      ? data.grades.filter(g => g.is_active && activeLevels.some(l => l.id === g.level_id)) : [];
    const initial = document.createElement('option'); initial.value = ''; initial.textContent = options.length ? 'Select a parent' : 'No active parents available'; parent.append(initial);
    for (const item of options) {
      const option = document.createElement('option'); option.value = String(item.id);
      const level = type === 'section' ? activeLevels.find(l => l.id === item.level_id) : null;
      option.textContent = level ? level.name + ' / ' + item.name : item.name; parent.append(option);
    }
    submit.disabled = !data || busy || reviewRequired || (type !== 'level' && options.length === 0);
  }
  async function reload() {
    busy = true; data = null; controls(); tell('Loading school structure…');
    try {
      const response = await fetch('api_school_structure.php', {credentials:'same-origin', headers:{'X-Requested-With':'XMLHttpRequest'}});
      const result = await response.json();
      if (!response.ok || result.status !== 'success' || !Array.isArray(result.levels) || !Array.isArray(result.grades) || !Array.isArray(result.sections)) throw new Error('load');
      data = result; render(); reviewRequired = false;
      tell('Current structure loaded. Review it before adding an item.');
    } catch (_) { $('structure').replaceChildren(); tell('Could not load the structure. Adding is disabled; reload and try again.'); }
    finally { busy = false; controls(); }
  }
  kind.addEventListener('change', controls);
  $('refresh').addEventListener('click', () => { if (!busy) reload(); });
  form.addEventListener('submit', async event => {
    event.preventDefault(); if (!data || busy || reviewRequired || !form.reportValidity()) return;
    const order = Number($('sort-order').value), id = Number(parent.value);
    if (!Number.isInteger(order) || order < -2147483648 || order > 2147483647 || (kind.value !== 'level' && (!Number.isSafeInteger(id) || id <= 0))) {
      tell('Select an active parent and enter a valid whole-number order.'); return;
    }
    const payload = {kind:kind.value, name:$('name').value, sort_order:order};
    if (kind.value !== 'level') payload.parent_id = id;
    busy = true; controls(); tell('Adding item…');
    try {
      const response = await fetch('api_school_structure_create.php', {method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/json','X-CSRF-Token':csrf,'X-Requested-With':'XMLHttpRequest'}, body:JSON.stringify(payload)});
      const result = await response.json();
      if (response.status >= 500) throw new Error('unknown');
      if (!response.ok || result.status !== 'success') {
        tell(response.status === 409 ? 'A matching item already exists. Review the structure.' : 'Item was not added. Check your input and permissions.');
      } else {
        $('name').value = ''; await reload(); return;
      }
    } catch (_) {
      reviewRequired = true; tell('The result could not be confirmed. Reload and review the structure before trying again; do not submit the same item blindly.');
    } finally { busy = false; controls(); }
  });
  reload();
})();

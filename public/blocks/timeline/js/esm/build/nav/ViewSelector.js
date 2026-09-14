import i from"@moodle/lms/core/String";import{useAriaLabels as b}from"../common/useAriaLabels";import{jsx as t,jsxs as u}from"react/jsx-runtime";/**
 * Sort-order (dates / courses) selector for the Timeline block.
 *
 * Matches the DOM structure of the legacy nav-view-selector.mustache template, except for the
 * ARIA roles: this is a dropdown of two sort options, so it uses the menu pattern that DayFilter
 * and Bootstrap's own dropdown JS already implement, rather than the tablist the legacy template
 * declared but never wired up.
 *
 * @module     block_timeline/nav/ViewSelector
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */const s="timeline-view-selector-current-selection",a=[{name:"sortbydates",labelKey:"sortbydates"},{name:"sortbycourses",labelKey:"sortbycourses"}];function p({activeOrder:n,onChange:l}){const r="menusortby",{buttonLabel:o,itemLabels:d}=b("ariaviewselector","ariaviewselectoroption",a),m=a.find(e=>e.name===n)??a[0];return u("div",{"data-region":"view-selector",className:"dropdown mb-1",children:[t("button",{type:"button",className:"btn btn-outline-secondary dropdown-toggle icon-no-margin","data-bs-toggle":"dropdown","aria-haspopup":"true","aria-expanded":"false","aria-label":o,"aria-controls":r,title:o,"aria-describedby":s,children:t("span",{id:s,"data-active-item-text":"",children:t(i,{identifier:m.labelKey,component:"block_timeline",children:""})})}),t("div",{id:r,role:"menu",className:"dropdown-menu dropdown-menu-end","data-show-active-item":"",children:a.map(e=>t("a",{className:`dropdown-item${n===e.name?" active dropdown-item-active":""}`,href:"#","data-filtername":e.name,"aria-current":n===e.name?"true":void 0,"aria-label":d[e.name],role:"menuitem",onClick:c=>{c.preventDefault(),l(e.name)},children:t(i,{identifier:e.labelKey,component:"block_timeline",children:""})},e.name))})]})}export{p as default};

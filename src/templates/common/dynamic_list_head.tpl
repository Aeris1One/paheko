<table class="list">
	<thead class="userOrder">
		<tr>
			{foreach from=$list->getHeaderColumns() key="key" item="column"}
			<td class="{if $list->order == $key}cur {if $list->desc}desc{else}asc{/if}{/if}">
				{$column.label}
				{if isset($column['select'])}
				<a href="{$list->orderURL($key, false)}" class="icn up">&uarr;</a>
				<a href="{$list->orderURL($key, true)}" class="icn dn">&darr;</a>
				{/if}
			</td>
			{/foreach}
			<td></td>
		</tr>
	</thead>
	<tbody>
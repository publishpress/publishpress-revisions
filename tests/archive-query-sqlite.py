"""Execute emitted archive SQL against 30,000 historical revisions (SQLite, not MySQL)."""
import json, sqlite3, subprocess, os
from pathlib import Path
root=Path(__file__).resolve().parents[1]
result=subprocess.run([os.environ.get('PHP_BINARY', 'php'),str(root/'tests/security/archive-scope.php'),'--sql'],capture_output=True,text=True,check=True)
queries=json.loads(result.stdout.strip())
db=sqlite3.connect(':memory:')
db.row_factory=sqlite3.Row
db.execute('CREATE TABLE wp_posts (ID INTEGER PRIMARY KEY, post_type TEXT, post_status TEXT, post_title TEXT, post_date TEXT, post_date_gmt TEXT, post_modified TEXT, post_modified_gmt TEXT, post_author INTEGER, post_parent INTEGER, post_name TEXT, post_mime_type TEXT)')
db.execute('CREATE INDEX post_parent ON wp_posts(post_parent,post_type)')
db.execute('CREATE INDEX post_type_date ON wp_posts(post_type,post_modified,ID)')
db.execute('CREATE TABLE wp_postmeta (meta_id INTEGER PRIMARY KEY,post_id INTEGER,meta_key TEXT,meta_value)')
db.execute('CREATE INDEX post_id ON wp_postmeta(post_id,meta_key)')
def row(id,type='revision',parent=20,author=5,name=None,title=None):
    date='2025-01-01 00:00:00'
    return (id,type,'inherit' if type=='revision' else 'publish',title or f'Item {id}',date,date,date,date,author,parent,name or f'{parent}-revision-v1','')
db.executemany('INSERT INTO wp_posts VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',[row(id,'late_type',0,7) for id in range(20,120)])
db.executemany('INSERT INTO wp_posts VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',[row(id,parent=20+(id-1000)//300,author=88 if id==1001 else 5,title='needle' if id==1005 else None) for id in range(1000,31000)])
db.executemany('INSERT INTO wp_posts VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',[
    row(40000,parent=20),row(40001,parent=40000),row(200,'post',0),row(50000,parent=200),
    row(50001,parent=200),row(50002,parent=50001),row(60000,parent=20,name='20-autosave-v1'),row(60001,parent=999999),
])
db.executemany('INSERT INTO wp_postmeta(post_id,meta_key,meta_value) VALUES (?,?,?)',[
    (1000,'_rvy_approved_by',9),(1000,'_rvy_published_gmt','2025-01-01 00:00:00'),
])
first=list(db.execute(queries['first']))
assert len(first)==21 and [r['ID'] for r in first]==list(range(1000,1021))
assert not any(r['post_parent']==21 for r in first)
assert 'COUNT(' not in queries['first']
authors=list(db.execute(queries['authors']))
assert len(authors)==21 and not any(r['ID']==1001 for r in authors)
assert all(r['post_author']==5 and r['origin_post_author']==7 for r in authors)
assert [r['ID'] for r in db.execute(queries['date'])]==[1000]
assert [r['ID'] for r in db.execute(queries['search'])]==[1005]
all_types=list(db.execute(queries['types'].replace('LIMIT 0,21','LIMIT 0,40000')))
ids={r['ID'] for r in all_types}
assert {40000,40001}.issubset(ids)
assert not ids.intersection({50000,50001,50002,60000,60001})
assert all(r['origin_post_type']=='late_type' for r in all_types)
assert len(ids)==30002
print('PASS: real SQL filtering and ordering over 30,000 revisions, enabled/nested parent types, author/date/approval/search combinations, autosaves, and missing parents (SQLite).')

"""Synthetic-only inputs and independent image checks for the local candidate."""
import importlib.util
import json
import shutil
import struct
import sys
import zlib
from pathlib import Path
from PIL import Image

PROJECT = Path(__file__).resolve().parents[1]
ROOT = Path(sys.argv[2]).resolve()
if ROOT.name != "codex-pixel-060-metadata-audit-20261007":
    raise RuntimeError("Dedicated synthetic laboratory required")

def recipe():
    spec = importlib.util.spec_from_file_location("fixture_recipe", ROOT / "previous_audit.py")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    module.REPORT = ROOT / "outputs"
    return module

def generate():
    ROOT.mkdir(exist_ok=False)
    shutil.copyfile(PROJECT / "reports/metadata-imagick-certification-0.6.0-20261007/reproduction/previous_audit.py", ROOT / "previous_audit.py")
    r = recipe()
    r.generate()
    f = ROOT / "fixtures"
    j = (f / "jpeg-clean.jpg").read_bytes()
    p = (f / "png-clean.png").read_bytes()
    records = []
    refused = {"jpeg-orientation6":"METADATA_ORIENTATION", "png-orientation6":"METADATA_ORIENTATION",
               "jpeg-provenance":"METADATA_PROVENANCE", "png-provenance":"METADATA_PROVENANCE",
               "jpeg-all":"METADATA_PROVENANCE", "jpeg-all-no-icc":"METADATA_PROVENANCE",
               "jpeg-app0-thumbnail":"METADATA_REVIEW", "png-unknown":"METADATA_REVIEW"}
    for path in sorted(f.iterdir()):
        records.append({"file":path.name,"error":refused.get(path.stem)})
    def case(name, data, error=None):
        (f / name).write_bytes(data)
        records.append({"file":name,"error":error})
    def jpg(payload, marker=0xfe):
        return j[:2] + r.segment(marker, payload) + j[2:]
    def png(name, payload):
        parts=r.png_chunks(p)
        return p[:8]+parts[0][2]+r.chunk(name,payload)+b"".join(x[2] for x in parts[1:])
    case("jpeg-comment.jpg", jpg(b"Synthetic comment"))
    progressive=(f/"jpeg-progressive.jpg").read_bytes()
    scan_start=progressive.index(b"\xff\xda")
    later_table=progressive.index(b"\xff\xc4",scan_start+2)
    case("jpeg-interscan-comment.jpg",progressive[:later_table]+r.segment(0xfe,b"synthetic interscan comment")+progressive[later_table:])
    for orientation in range(1,9):
        case(f"jpeg-orientation-{orientation}.jpg",jpg(r.exif(orientation,gps=True,rich=True),0xe1),None if orientation==1 else "METADATA_ORIENTATION")
        case(f"png-orientation-{orientation}.png",png("eXIf",r.exif(orientation,gps=True,rich=True)[6:]),None if orientation==1 else "METADATA_ORIENTATION")
    case("jpeg-thumbnail.jpg",jpg(r.exif(thumbnail=True),0xe1),"METADATA_REVIEW")
    case("jpeg-short-app.jpg",j[:2]+b"\xff\xe1\x00\x01"+j[2:],"METADATA_INVALID")
    case("jpeg-truncated.jpg",j[:-5],"METADATA_INVALID")
    case("jpeg-no-eoi.jpg",j[:-2],"METADATA_INVALID")
    case("jpeg-trailing.jpg",j+b"bad","METADATA_INVALID")
    case("jpeg-exif-cycle.jpg",jpg(b"Exif\0\0II\x2a\0"+struct.pack("<I",8)+struct.pack("<HHHI",1,0x8769,4,1)+struct.pack("<II",8,0),0xe1),"METADATA_INVALID")
    case("jpeg-bad-exif.jpg",jpg(b"Exif\0\0II\x2a\0"+struct.pack("<I",0xffffffff),0xe1),"METADATA_INVALID")
    xmpprefix=b"http://ns.adobe.com/xap/1.0/\0"
    case("jpeg-xmp-unknown.jpg",jpg(xmpprefix+b'<x:xmpmeta xmlns:x="adobe:ns:meta/" xmlns:z="urn:unknown"/>',0xe1),"METADATA_REVIEW")
    case("jpeg-xmp-entity.jpg",jpg(xmpprefix+b'<!DOCTYPE x [<!ENTITY e SYSTEM "file:///private">]><x>&e;</x>',0xe1),"METADATA_REVIEW")
    case("jpeg-xmp-null.jpg",jpg(xmpprefix+b"<x>\0</x>",0xe1),"METADATA_REVIEW")
    case("jpeg-xmp-malformed.jpg",jpg(xmpprefix+b"<x>",0xe1),"METADATA_INVALID")
    case("jpeg-unknown-app.jpg",jpg(b"Unknown",0xe3),"METADATA_REVIEW")
    case("jpeg-metadata-limit.jpg",j[:2]+r.segment(0xfe,b"x"*60000)*36+j[2:],"METADATA_LIMIT")
    icc=(f/"jpeg-icc.jpg").read_bytes()
    iccparts,_=r.jpeg_parts(icc)
    block=next(raw for marker,payload,raw in iccparts if marker==0xe2)
    case("jpeg-duplicate-icc.jpg",icc[:2]+block+icc[2:],"METADATA_INVALID")
    case("jpeg-bad-dqt.jpg",j.replace(b"\xff\xdb\x00C",b"\xff\xdb\x00B",1),"METADATA_INVALID")
    case("png-text-unknown.png",png("tEXt",b"Unknown\0test"),"METADATA_REVIEW")
    case("png-text-null.png",png("tEXt",b"Author\0bad\0data"),"METADATA_REVIEW")
    case("png-truncated-text.png",png("tEXt",b"Author"),"METADATA_INVALID")
    case("png-bad-ztxt.png",png("zTXt",b"Author\0\0invalid"),"METADATA_INVALID")
    case("png-bomb-ztxt.png",png("zTXt",b"Author\0\0"+zlib.compress(b"a"*300000)),"METADATA_INVALID")
    case("png-short-itxt.png",png("iTXt",b"Author\0\0"),"METADATA_INVALID")
    case("png-bad-exif.png",png("eXIf",b"invalid"),"METADATA_INVALID")
    case("png-invalid-time.png",png("tIME",struct.pack(">H5B",2026,14,1,0,0,0)),"METADATA_INVALID")
    case("png-crc.png",p[:29]+bytes([p[29]^1])+p[30:],"METADATA_INVALID")
    case("png-truncated.png",p[:-1],"METADATA_INVALID")
    case("png-large-length.png",p[:8]+b"\xff\xff\xff\xffIHDR"+p[16:],"METADATA_INVALID")
    chunks=r.png_chunks(p)
    case("png-duplicate-ihdr.png",p[:8]+chunks[0][2]+p[8:],"METADATA_INVALID")
    case("png-no-ihdr.png",p[:8]+b"".join(x[2] for x in chunks[1:]),"METADATA_INVALID")
    case("png-order.png",p[:-12]+r.chunk("gAMA",struct.pack(">I",45455))+p[-12:],"METADATA_INVALID")
    case("png-trailing.png",p+b"x","METADATA_INVALID")
    case("png-many-chunks.png",p[:33]+r.chunk("tEXt",b"Author\0x")*4096+p[33:],"METADATA_LIMIT")
    case("png-alpha-text.png",(f/"png-alpha.png").read_bytes()[:33]+r.chunk("tEXt",b"Author\0synthetic")+(f/"png-alpha.png").read_bytes()[33:])
    case("png-icc-text.png",(f/"png-icc.png").read_bytes()[:33]+r.chunk("tEXt",b"Author\0synthetic")+(f/"png-icc.png").read_bytes()[33:])
    case("jpeg-icc-comment.jpg",icc[:2]+r.segment(0xfe,b"synthetic")+icc[2:])
    case("png-color-text.png",(f/"png-gamma.png").read_bytes()[:33]+r.chunk("tEXt",b"Author\0synthetic")+(f/"png-gamma.png").read_bytes()[33:])
    (ROOT/"cases.json").write_text(json.dumps(records,indent=2))
    print(f"{len(records)} synthetic cases prepared")

def verify():
    r=recipe()
    rows=json.loads((ROOT/"outputs/results.json").read_text())
    count=0
    proof=[]
    for row in rows:
        if row.get("error"):
            continue
        a=ROOT/"fixtures"/row["file"]
        b=ROOT/"outputs"/row["file"]
        before,after=r.inventory(a),r.inventory(b)
        assert before["decoded_rgba_sha256"]==after["decoded_rgba_sha256"],row["file"]
        assert before["profile_sha256"]==after["profile_sha256"],row["file"]
        assert before["dimensions"]==after["dimensions"]
        assert before["alpha_extrema"]==after["alpha_extrema"]
        if before["format"]=="PNG":
            assert before["idat_sha256"]==after["idat_sha256"]
            for name in ["iCCP","sRGB","gAMA","cHRM","pHYs","tRNS"]:
                assert [raw for n,_,raw in r.png_chunks(a.read_bytes()) if n==name]==[raw for n,_,raw in r.png_chunks(b.read_bytes()) if n==name]
        else:
            assert jpeg_image_bytes(a.read_bytes())==jpeg_image_bytes(b.read_bytes()),row["file"]
        count+=1
        if row["file"] in ("jpeg-orientation-1.jpg","png-exif.png"):
            proof.append({"fixture":row["file"],"before":before,"after":after,"removed_categories":row["categories"],"bytes_removed":row["removed_bytes"]})
    (ROOT/"outputs/independent-proof.json").write_text(json.dumps({"successes":count,"proof":proof},indent=2))
    print(f"{count} independent pixel/color/alpha/compressed-payload comparisons PASS")

def jpeg_image_bytes(data):
    """Independent full scan walker, including progressive inter-scan metadata."""
    out=bytearray(data[:2]);p=2
    while p<len(data):
        start=p
        assert data[p]==255
        while data[p]==255:p+=1
        marker=data[p];p+=1
        if marker==0xd9:
            out.extend(data[start:p]);assert p==len(data);break
        length=struct.unpack(">H",data[p:p+2])[0];p+=length
        if marker==0xda:
            while p<len(data):
                if data[p]!=255:p+=1;continue
                q=p+1
                while data[q]==255:q+=1
                if data[q]==0 or 0xd0<=data[q]<=0xd7:p=q+1;continue
                break
        if marker<0xe0 or marker>0xef and marker!=0xfe:out.extend(data[start:p])
    return bytes(out)

{"generate":generate,"verify":verify}[sys.argv[1]]()

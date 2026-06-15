"""
Generate all WordPress.org plugin assets for Tahhan Conflict Detective:
  - icon-128x128.png  / icon-256x256.png
  - banner-772x250.png / banner-1544x500.png
  - screenshot-1.png … screenshot-7.png
"""

from PIL import Image, ImageDraw, ImageFont
import os, math

OUT   = "/Users/mustafa/plugin conflict detectir/.wordpress-org"
FONTS = "/System/Library/Fonts/HelveticaNeue.ttc"

os.makedirs(OUT, exist_ok=True)

# ── colour palette ─────────────────────────────────────────────────────────────
BG        = (240, 240, 241)   # WP admin bg
SIDEBAR   = (29,  35,  39)    # WP dark sidebar
TOPBAR    = (29,  35,  39)
BLUE      = (34, 113, 177)    # WP admin blue
BLUE_LT   = (114, 174, 230)
BLUE_DK   = (18,  62, 105)
WHITE     = (255, 255, 255)
BORDER    = (195, 196, 199)
TEXT_DK   = (29,  35,  39)
TEXT_MD   = (80,  87,  94)
TEXT_LT   = (140, 143, 148)
GREEN     = (0,  163,  42)
RED       = (214,  54,  56)
AMBER     = (220, 148,  20)
CARD      = (255, 255, 255)
ACCENT    = (34, 113, 177)

# ── font loader ────────────────────────────────────────────────────────────────
_font_cache = {}
def F(size, bold=False):
    idx = 1 if bold else 0
    key = (size, bold)
    if key not in _font_cache:
        try:
            _font_cache[key] = ImageFont.truetype(FONTS, size, index=idx)
        except:
            _font_cache[key] = ImageFont.load_default()
    return _font_cache[key]

# ── drawing helpers ────────────────────────────────────────────────────────────
def rrect(draw, xy, r, fill, outline=None, width=1):
    x0,y0,x1,y1 = xy
    draw.rounded_rectangle([x0,y0,x1,y1], radius=r, fill=fill,
                            outline=outline, width=width)

def text_c(draw, cx, y, txt, font, fill):
    bb = draw.textbbox((0,0), txt, font=font)
    draw.text((cx-(bb[2]-bb[0])//2, y), txt, font=font, fill=fill)

def text_r(draw, x, y, txt, font, fill):
    draw.text((x, y), txt, font=font, fill=fill)

def badge(draw, x, y, txt, bg, fg=WHITE, font=None):
    if font is None: font = F(11)
    bb = draw.textbbox((0,0), txt, font=font)
    w = bb[2]-bb[0]+14; h = bb[3]-bb[1]+8
    rrect(draw, (x,y,x+w,y+h), h//2, bg)
    draw.text((x+7, y+4-bb[1]), txt, font=font, fill=fg)
    return w+6

def wp_chrome(img, draw, title, active_menu="Conflict Detective"):
    """Draw WordPress admin chrome: topbar + sidebar + breadcrumb."""
    W, H = img.size
    # top bar
    draw.rectangle([0,0,W,32], fill=TOPBAR)
    draw.text((12, 8), "🔒  yoursite.com  /  wp-admin", font=F(12), fill=(160,170,180))
    draw.text((W-110, 8), "Howdy, Admin ▾", font=F(12), fill=(160,170,180))

    # sidebar
    SB = 160
    draw.rectangle([0,32,SB,H], fill=SIDEBAR)
    menus = ["Dashboard","Posts","Media","Pages","Comments",
             "Appearance","Plugins","Users","Tools","Settings",
             "Conflict Detective"]
    for i,m in enumerate(menus):
        y = 38 + i*28
        if m == active_menu:
            draw.rectangle([0,y,SB,y+26], fill=BLUE)
        col = WHITE if m==active_menu else (160,170,180)
        draw.text((14, y+5), m, font=F(12, bold=(m==active_menu)), fill=col)

    # content header strip
    draw.rectangle([SB,32,W,76], fill=WHITE)
    draw.rectangle([SB,76,W,77], fill=BORDER)
    draw.text((SB+20, 48), title, font=F(20, bold=True), fill=TEXT_DK)

    return SB  # return sidebar width

def stat_card(draw, x, y, w, h, label, value, color, sub=None):
    rrect(draw, (x,y,x+w,y+h), 6, CARD, BORDER, 1)
    draw.rectangle([x,y,x+4,y+h], fill=color)   # colour stripe left
    draw.text((x+16, y+14), value, font=F(28, bold=True), fill=TEXT_DK)
    draw.text((x+16, y+50), label, font=F(12), fill=TEXT_MD)
    if sub:
        draw.text((x+16, y+68), sub, font=F(11), fill=TEXT_LT)

def section_header(draw, x, y, w, title):
    draw.rectangle([x,y,x+w,y+36], fill=(247,247,248))
    draw.rectangle([x,y,x+w,y+1], fill=BORDER)
    draw.rectangle([x,y+36,x+w,y+37], fill=BORDER)
    draw.text((x+14, y+10), title, font=F(13, bold=True), fill=TEXT_DK)

def table_row(draw, x, y, w, cols, widths, alt=False):
    bg = (248,249,250) if alt else WHITE
    draw.rectangle([x,y,x+w,y+28], fill=bg)
    draw.rectangle([x,y+28,x+w,y+29], fill=(238,239,240))
    cx = x+12
    for txt,cw in zip(cols,widths):
        draw.text((cx, y+8), str(txt), font=F(12), fill=TEXT_DK)
        cx += cw

# ═══════════════════════════════════════════════════════════════════════════════
# ICON
# ═══════════════════════════════════════════════════════════════════════════════
def make_icon(size):
    img  = Image.new("RGBA",(size,size),(0,0,0,0))
    draw = ImageDraw.Draw(img)
    p    = size//12
    r    = size//7

    # rounded background
    rrect(draw,(p,p,size-p,size-p),r,(22,40,65,255))

    # magnifying glass
    cx,cy = size//2, int(size*0.44)
    cr = int(size*0.22); cw=max(3,size//22)
    draw.ellipse([cx-cr,cy-cr,cx+cr,cy+cr], fill=BLUE)
    draw.ellipse([cx-cr+cw,cy-cr+cw,cx+cr-cw,cy+cr-cw], fill=(22,40,65))

    ang=0.7071
    x1=int(cx+(cr-cw)*ang); y1=int(cy+(cr-cw)*ang)
    x2=int(x1+cr*0.7*ang);  y2=int(y1+cr*0.7*ang)
    draw.line([x1,y1,x2,y2],fill=BLUE,width=max(3,size//24))

    # inner "T"
    fs=max(10,int(cr*0.85))
    fn=F(fs,bold=True)
    bb=draw.textbbox((0,0),"T",font=fn)
    draw.text((cx-(bb[2]-bb[0])//2,cy-(bb[3]-bb[1])//2-bb[1]),"T",font=fn,fill=WHITE)

    # small dot accent
    ds=size//10
    draw.ellipse([size-p-ds-2,size-p-ds-2,size-p-2,size-p-2],fill=BLUE_LT)

    return img

icon256=make_icon(256); icon256.save(f"{OUT}/icon-256x256.png")
icon128=make_icon(128); icon128.save(f"{OUT}/icon-128x128.png")
print("Icons ✅")

# ═══════════════════════════════════════════════════════════════════════════════
# BANNER
# ═══════════════════════════════════════════════════════════════════════════════
def make_banner(W,H):
    img  = Image.new("RGB",(W,H),(22,40,65))
    draw = ImageDraw.Draw(img)

    # subtle grid lines
    for x in range(0,W,W//16):
        draw.line([x,0,x,H],fill=(255,255,255,15))
    for y in range(0,H,H//8):
        draw.line([0,y,W,y],fill=(255,255,255,15))

    # left accent bar
    draw.rectangle([0,0,max(5,W//120),H],fill=BLUE)

    # large magnifying-glass right side
    cx=int(W*0.79); cy=int(H*0.48); cr=int(H*0.30); cw=max(8,H//28)
    # glow
    for g in range(6,0,-1):
        alpha=int(30*g/6)
        c=(34+alpha,113+alpha,177+alpha)
        draw.ellipse([cx-cr-g*3,cy-cr-g*3,cx+cr+g*3,cy+cr+g*3],outline=c,width=2)
    draw.ellipse([cx-cr,cy-cr,cx+cr,cy+cr],fill=BLUE)
    draw.ellipse([cx-cr+cw,cy-cr+cw,cx+cr-cw,cy+cr-cw],fill=(22,40,65))
    ang=0.7071
    x1=int(cx+(cr-cw)*ang); y1=int(cy+(cr-cw)*ang)
    draw.line([x1,y1,int(x1+cr*0.75*ang),int(y1+cr*0.75*ang)],fill=BLUE,width=max(6,H//35))
    # "?" inside lens
    fs2=max(14,int((cr-cw)*0.65))
    fn2=F(fs2,bold=True)
    bb=draw.textbbox((0,0),"?",font=fn2)
    draw.text((cx-(bb[2]-bb[0])//2,cy-(bb[3]-bb[1])//2-bb[1]),"?",font=fn2,fill=BLUE_LT)

    # --- left text ---
    PL=int(W*0.07)
    # "Tahhan" in blue
    fn_big=F(max(28,H//5),bold=True)
    draw.text((PL,int(H*0.15)),"Tahhan",font=fn_big,fill=BLUE_LT)
    bb=draw.textbbox((0,0),"Tahhan",font=fn_big)
    y2=int(H*0.15)+bb[3]-bb[1]+int(H*0.04)
    draw.text((PL,y2),"Conflict Detective",font=fn_big,fill=WHITE)
    bb2=draw.textbbox((0,0),"Conflict Detective",font=fn_big)
    y3=y2+bb2[3]-bb2[1]+int(H*0.06)

    # tagline
    fn_tag=F(max(11,H//18))
    draw.text((PL,y3),"Automatically detects which plugin broke your WordPress site.",
              font=fn_tag,fill=(180,195,215))

    # version badge
    y4=int(H*0.82)
    fn_b=F(max(10,H//22),bold=True)
    txt="v2.5.2  •  Free  •  WordPress.org"
    bb3=draw.textbbox((0,0),txt,font=fn_b)
    bw=bb3[2]-bb3[0]+28; bh=bb3[3]-bb3[1]+12
    rrect(draw,(PL,y4,PL+bw,y4+bh),bh//2,BLUE)
    draw.text((PL+14,y4+6-bb3[1]),txt,font=fn_b,fill=WHITE)

    return img

make_banner(1544,500).save(f"{OUT}/banner-1544x500.png")
make_banner(772,250).save(f"{OUT}/banner-772x250.png")
print("Banners ✅")

# ═══════════════════════════════════════════════════════════════════════════════
# SCREENSHOTS  (1280 × 800)
# ═══════════════════════════════════════════════════════════════════════════════
SW,SH=1280,800

# ── screenshot-1 : Dashboard ───────────────────────────────────────────────────
img=Image.new("RGB",(SW,SH),BG); draw=ImageDraw.Draw(img)
SB=wp_chrome(img,draw,"Conflict Detective — Dashboard")
CX=SB+20; CY=90; CW=SW-SB-40

# tab bar
tabs=[("Dashboard",True),("Conflict Scanner",False),("Safe Mode",False),
      ("Conflict Wizard",False),("Error Log",False),("Change History",False),("Health Scan",False)]
tx=CX
for t,active in tabs:
    fn=F(12,bold=active)
    bb=draw.textbbox((0,0),t,font=fn)
    w=bb[2]-bb[0]+24
    col=BLUE if active else TEXT_MD
    draw.text((tx+12,CY+10),t,font=fn,fill=col)
    if active:
        draw.rectangle([tx,CY+32,tx+w,CY+34],fill=BLUE)
    tx+=w+4
draw.rectangle([CX,CY+34,CX+CW,CY+35],fill=BORDER)

# stat cards row
CY+=50
cw4=(CW-30)//4
cards=[("Active Plugins","24",BLUE,"All running"),
       ("Recent Changes","3",AMBER,"Last 24 hours"),
       ("Error Count","7",RED,"Last 7 days"),
       ("Suspect Plugin","WooCommerce",GREEN,"87% confidence")]
for i,(lbl,val,col,sub) in enumerate(cards):
    stat_card(draw,CX+i*(cw4+10),CY,cw4,90,lbl,val,col,sub)

CY+=110
# two-column section
LC=(CW-20)//2

# recent changes card
rrect(draw,(CX,CY,CX+LC,CY+220),6,CARD,BORDER,1)
section_header(draw,CX,CY,LC,"Recent Plugin Changes")
rows=[("WooCommerce","Updated","8.3→8.4","2 hrs ago"),
      ("Yoast SEO","Activated","22.1","5 hrs ago"),
      ("Akismet","Deactivated","5.3.2","1 day ago"),
      ("Contact Form 7","Updated","5.8→5.9","2 days ago")]
for j,(name,action,ver,when) in enumerate(rows):
    y=CY+42+j*40
    draw.rectangle([CX+14,y,CX+14+8,y+22],fill=GREEN if action=="Activated" else AMBER if action=="Updated" else RED,)
    draw.text((CX+30,y+3),name,font=F(12,bold=True),fill=TEXT_DK)
    draw.text((CX+30,y+18),f"{action} • {ver}",font=F(11),fill=TEXT_MD)
    draw.text((CX+LC-80,y+10),when,font=F(11),fill=TEXT_LT)

# likely culprit card
RCX=CX+LC+20
rrect(draw,(RCX,CY,RCX+LC,CY+220),6,CARD,BORDER,1)
section_header(draw,RCX,CY,LC,"Likely Conflict Culprit")
draw.text((RCX+20,CY+52),"WooCommerce",font=F(22,bold=True),fill=TEXT_DK)
draw.text((RCX+20,CY+82),"Confidence score",font=F(12),fill=TEXT_MD)
# progress bar
draw.rectangle([RCX+20,CY+104,RCX+LC-20,CY+120],fill=(220,225,230))
draw.rectangle([RCX+20,CY+104,RCX+20+int((LC-40)*0.87),CY+120],fill=GREEN)
draw.text((RCX+20,CY+126),"87%  —  Updated 2 hours before first error spike",font=F(11),fill=TEXT_MD)
draw.text((RCX+20,CY+150),"3 errors attributed to this plugin",font=F(12),fill=RED)
rrect(draw,(RCX+20,CY+175,RCX+20+140,CY+197),4,BLUE)
draw.text((RCX+30,CY+179),"View in Scanner →",font=F(12,bold=True),fill=WHITE)

img.save(f"{OUT}/screenshot-1.png")
print("Screenshot 1 ✅")

# ── screenshot-2 : Conflict Scanner ───────────────────────────────────────────
img=Image.new("RGB",(SW,SH),BG); draw=ImageDraw.Draw(img)
wp_chrome(img,draw,"Conflict Scanner")
CX=SB+20; CY=90; CW=SW-SB-40
draw.rectangle([CX,CY+34,CX+CW,CY+35],fill=BORDER)
CY+=50

# summary strip
rrect(draw,(CX,CY,CX+CW,CY+60),6,(235,244,255),BLUE_LT,1)
draw.text((CX+20,CY+10),"🔍  Scanner last ran: Today at 14:32  —  Found 2 active conflicts",font=F(13),fill=BLUE_DK)
rrect(draw,(CX+CW-140,CY+14,CX+CW-20,CY+38),4,BLUE)
draw.text((CX+CW-130,CY+18),"Run Scan",font=F(12,bold=True),fill=WHITE)

CY+=78
section_header(draw,CX,CY,CW,"Detected Conflicts")
# header row
draw.rectangle([CX,CY+36,CX+CW,CY+60],fill=(247,247,248))
for col,x2 in [("Plugin",CX+14),("Confidence",CX+300),("Error Count",CX+460),("First Seen",CX+620),("Status",CX+790),("Action",CX+950)]:
    draw.text((x2,CY+46),col,font=F(11,bold=True),fill=TEXT_LT)

conflicts=[
    ("WooCommerce 8.4.0","87%",GREEN,"12 errors","2026-06-09","Active"),
    ("Elementor 3.21","61%",AMBER,"5 errors","2026-06-10","Active"),
    ("Contact Form 7","28%",(160,170,180),"2 errors","2026-06-08","Resolved"),
]
for j,(name,conf,col,errs,date,status) in enumerate(conflicts):
    y=CY+60+j*48
    bg=(255,255,255) if j%2==0 else (248,249,250)
    draw.rectangle([CX,y,CX+CW,y+48],fill=bg)
    draw.rectangle([CX,y+48,CX+CW,y+49],fill=BORDER)
    draw.rectangle([CX,y,CX+4,y+48],fill=col)
    draw.text((CX+14,y+10),name,font=F(12,bold=True),fill=TEXT_DK)
    draw.text((CX+14,y+28),"Last updated 2 days ago",font=F(10),fill=TEXT_LT)
    badge(draw,CX+300,y+14,conf,col)
    draw.text((CX+460,y+18),errs,font=F(12),fill=TEXT_DK)
    draw.text((CX+620,y+18),date,font=F(12),fill=TEXT_DK)
    sc=GREEN if status=="Resolved" else RED
    badge(draw,CX+790,y+14,status,sc)
    if status!="Resolved":
        rrect(draw,(CX+950,y+12,CX+1050,y+34),4,BORDER)
        draw.text((CX+960,y+16),"Mark Resolved",font=F(11),fill=TEXT_MD)

img.save(f"{OUT}/screenshot-2.png")
print("Screenshot 2 ✅")

# ── screenshot-3 : Safe Testing Mode ─────────────────────────────────────────
img=Image.new("RGB",(SW,SH),BG); draw=ImageDraw.Draw(img)
wp_chrome(img,draw,"Safe Testing Mode")
CX=SB+20; CY=90; CW=SW-SB-40
draw.rectangle([CX,CY+34,CX+CW,CY+35],fill=BORDER)
CY+=55

# amber active banner
rrect(draw,(CX,CY,CX+CW,CY+64),6,(255,248,230),(220,148,20),2)
draw.rectangle([CX,CY,CX+5,CY+64],fill=AMBER)
draw.text((CX+20,CY+10),"⚠  Safe Mode is ACTIVE — 3 plugins disabled for your session only",
          font=F(14,bold=True),fill=(120,80,0))
draw.text((CX+20,CY+34),"Visitors see the normal live site. Only your admin session is affected.",
          font=F(12),fill=(140,100,20))
rrect(draw,(CX+CW-155,CY+16,CX+CW-15,CY+40),4,RED)
draw.text((CX+CW-148,CY+20),"Stop Safe Mode",font=F(12,bold=True),fill=WHITE)

CY+=80
rrect(draw,(CX,CY,CX+CW,CY+440),6,CARD,BORDER,1)
section_header(draw,CX,CY,CW,"Plugin Toggle List  —  3 disabled")
plugins=[
    ("Elementor","3.21.0","Page Builder","Disabled"),
    ("WooCommerce","8.4.0","eCommerce","Disabled"),
    ("Yoast SEO","22.1","SEO","Disabled"),
    ("Akismet Anti-Spam","5.3.2","Security","Active"),
    ("Contact Form 7","5.9","Forms","Active"),
    ("WP Super Cache","1.7.9","Caching","Active"),
    ("UpdraftPlus","1.24","Backup","Active"),
]
for j,(name,ver,cat,state) in enumerate(plugins):
    y=CY+42+j*52
    draw.rectangle([CX,y,CX+CW,y+52],fill=(248,249,250) if j%2 else WHITE)
    draw.rectangle([CX,y+52,CX+CW,y+53],fill=(238,239,240))
    sc=RED if state=="Disabled" else GREEN
    draw.ellipse([CX+20,y+18,CX+36,y+34],fill=sc)
    draw.text((CX+50,y+8),name,font=F(13,bold=True),fill=TEXT_DK)
    draw.text((CX+50,y+26),f"v{ver}  •  {cat}",font=F(11),fill=TEXT_MD)
    badge(draw,CX+CW-200,y+16,state,sc if state=="Active" else RED)
    # toggle button
    btn_c=GREEN if state=="Disabled" else RED
    btn_t="Enable" if state=="Disabled" else "Disable"
    rrect(draw,(CX+CW-120,y+14,CX+CW-20,y+36),4,btn_c)
    draw.text((CX+CW-105,y+18),btn_t,font=F(12,bold=True),fill=WHITE)

img.save(f"{OUT}/screenshot-3.png")
print("Screenshot 3 ✅")

# ── screenshot-4 : Conflict Wizard ────────────────────────────────────────────
img=Image.new("RGB",(SW,SH),BG); draw=ImageDraw.Draw(img)
wp_chrome(img,draw,"Conflict Wizard")
CX=SB+20; CY=90; CW=SW-SB-40
draw.rectangle([CX,CY+34,CX+CW,CY+35],fill=BORDER)
CY+=55

# wizard card
rrect(draw,(CX,CY,CX+CW,CY+500),6,CARD,BORDER,1)
section_header(draw,CX,CY,CW,"Step-by-step Conflict Diagnosis")

# step indicators
steps=["Choose Symptom","Analyse","Results"]
sx=CX+(CW-300)//2
for i,s in enumerate(steps):
    x=sx+i*150
    col=BLUE if i<2 else (200,210,220)
    draw.ellipse([x,CY+50,x+30,CY+80],fill=col)
    draw.text((x+9,CY+57),str(i+1),font=F(13,bold=True),fill=WHITE)
    draw.text((x-10,CY+86),s,font=F(11),fill=col)
    if i<2: draw.line([x+30,CY+65,x+150,CY+65],fill=BLUE,width=2)

# results box
RY=CY+120
rrect(draw,(CX+30,RY,CX+CW-30,RY+50),6,(235,244,255),BLUE_LT,1)
draw.text((CX+50,RY+15),"✓  Analysis complete — Symptom: White screen / PHP fatal error",
          font=F(13,bold=True),fill=BLUE_DK)

# culprit
draw.text((CX+40,RY+72),"Most Likely Cause",font=F(12,bold=True),fill=TEXT_LT)
draw.text((CX+40,RY+92),"WooCommerce 8.4.0",font=F(20,bold=True),fill=TEXT_DK)
draw.text((CX+40,RY+120),"Updated 2 hours before first fatal error.  3 PHP errors directly reference WooCommerce files.",
          font=F(12),fill=TEXT_MD)

# action plan
draw.text((CX+40,RY+160),"Recommended Actions",font=F(14,bold=True),fill=TEXT_DK)
actions=["Disable WooCommerce via Safe Mode and check if the white screen resolves.",
         "If resolved: check WooCommerce changelog for breaking changes in 8.4.0.",
         "Consider rolling back to 8.3.0 using a backup or a rollback plugin.",
         "Contact WooCommerce support with the error log entries below."]
for k,a in enumerate(actions):
    y=RY+186+k*34
    draw.ellipse([CX+40,y+4,CX+54,y+18],fill=BLUE)
    draw.text((CX+46,y+5),str(k+1),font=F(10,bold=True),fill=WHITE)
    draw.text((CX+62,y+4),a,font=F(12),fill=TEXT_DK)

# CTA button
rrect(draw,(CX+40,RY+330,CX+40+200,RY+356),4,BLUE)
draw.text((CX+55,RY+334),"Open in Safe Mode →",font=F(13,bold=True),fill=WHITE)

img.save(f"{OUT}/screenshot-4.png")
print("Screenshot 4 ✅")

# ── screenshot-5 : Error Log ───────────────────────────────────────────────────
img=Image.new("RGB",(SW,SH),BG); draw=ImageDraw.Draw(img)
wp_chrome(img,draw,"Error Log Viewer")
CX=SB+20; CY=90; CW=SW-SB-40
draw.rectangle([CX,CY+34,CX+CW,CY+35],fill=BORDER)
CY+=55

# filter bar
rrect(draw,(CX,CY,CX+CW,CY+44),6,CARD,BORDER,1)
filters=[("All (47)",True),("Fatal (3)",False),("Warning (18)",False),
         ("Notice (21)",False),("Deprecated (5)",False)]
fx=CX+12
for ft,active in filters:
    fn=F(12,bold=active)
    bb=draw.textbbox((0,0),ft,font=fn)
    w=bb[2]-bb[0]+20
    if active:
        rrect(draw,(fx,CY+8,fx+w,CY+34),4,BLUE)
        draw.text((fx+10,CY+12),ft,font=fn,fill=WHITE)
    else:
        rrect(draw,(fx,CY+8,fx+w,CY+34),4,BG,BORDER,1)
        draw.text((fx+10,CY+12),ft,font=fn,fill=TEXT_MD)
    fx+=w+8

CY+=56
section_header(draw,CX,CY,CW,"Error Log Entries")

errors=[
    ("FATAL","PHP Fatal error: Uncaught Error: Call to undefined function wc_get_cart_url()","WooCommerce","woocommerce/src/Cart/CartController.php:142","14:32:01"),
    ("WARNING","PHP Warning: Undefined array key 'shipping_method'","WooCommerce","woocommerce/includes/class-wc-checkout.php:88","14:31:55"),
    ("NOTICE","PHP Notice: Undefined variable $product in elementor plugin","Elementor","elementor/includes/widgets/woocommerce.php:220","14:31:40"),
    ("WARNING","PHP Warning: Cannot modify header information — headers already sent","WordPress Core","wp-includes/functions.php:1518","14:28:12"),
    ("FATAL","PHP Fatal error: Maximum execution time of 30 seconds exceeded","WooCommerce","woocommerce/includes/data-stores/class-wc-order.php:67","14:27:50"),
    ("DEPRECATED","Function get_magic_quotes_gpc() is deprecated","WordPress Core","wp-includes/load.php:741","13:55:03"),
]
type_colors={"FATAL":RED,"WARNING":AMBER,"NOTICE":BLUE,"DEPRECATED":(140,143,148)}
for j,(typ,msg,plugin,file,time) in enumerate(errors):
    y=CY+38+j*68
    bg=(255,248,248) if typ=="FATAL" else (255,250,240) if typ=="WARNING" else (255,255,255) if j%2==0 else (248,249,250)
    draw.rectangle([CX,y,CX+CW,y+68],fill=bg)
    draw.rectangle([CX,y+68,CX+CW,y+69],fill=BORDER)
    tc=type_colors.get(typ,TEXT_MD)
    draw.rectangle([CX,y,CX+5,y+68],fill=tc)
    badge(draw,CX+12,y+10,typ,tc)
    draw.text((CX+80,y+10),msg[:80]+("…" if len(msg)>80 else ""),font=F(12),fill=TEXT_DK)
    rrect(draw,(CX+80,y+34,CX+80+len(plugin)*7+16,y+52),10,BLUE_LT if "Core" not in plugin else (200,210,220))
    draw.text((CX+88,y+37),plugin,font=F(10,bold=True),fill=BLUE_DK if "Core" not in plugin else TEXT_MD)
    draw.text((CX+80+len(plugin)*7+26,y+38),file,font=F(10),fill=TEXT_LT)
    draw.text((CX+CW-80,y+10),time,font=F(11),fill=TEXT_LT)

img.save(f"{OUT}/screenshot-5.png")
print("Screenshot 5 ✅")

# ── screenshot-6 : Change History ─────────────────────────────────────────────
img=Image.new("RGB",(SW,SH),BG); draw=ImageDraw.Draw(img)
wp_chrome(img,draw,"Plugin Change History")
CX=SB+20; CY=90; CW=SW-SB-40
draw.rectangle([CX,CY+34,CX+CW,CY+35],fill=BORDER)
CY+=55

rrect(draw,(CX,CY,CX+CW,CY+490),6,CARD,BORDER,1)
section_header(draw,CX,CY,CW,"Audit Trail — All Plugin Events")

# col headers
for h2,x2 in [("Event",CX+70),("Plugin",CX+230),("Version Change",CX+480),("Date / Time",CX+700),("User",CX+920)]:
    draw.text((x2,CY+44),h2,font=F(11,bold=True),fill=TEXT_LT)

events=[
    ("Updated","WooCommerce","8.3.0 → 8.4.0","2026-06-09 14:30","admin",AMBER),
    ("Activated","Elementor","3.21.0","2026-06-09 10:15","admin",GREEN),
    ("Deactivated","Classic Editor","1.6.5","2026-06-08 16:42","admin",RED),
    ("Updated","Yoast SEO","22.0 → 22.1","2026-06-08 09:20","admin",AMBER),
    ("Installed","Contact Form 7","5.9.0","2026-06-07 11:05","admin",BLUE),
    ("Updated","Akismet","5.3.1 → 5.3.2","2026-06-06 08:30","admin",AMBER),
    ("Deleted","Hello Dolly","1.7.2","2026-06-05 14:00","admin",RED),
]
action_icons={"Updated":"↑","Activated":"✓","Deactivated":"○","Installed":"＋","Deleted":"✕"}
for j,(action,name,ver,dt,user,col) in enumerate(events):
    y=CY+64+j*56
    draw.rectangle([CX,y,CX+CW,y+56],fill=(248,249,250) if j%2 else WHITE)
    draw.rectangle([CX,y+56,CX+CW,y+57],fill=(238,239,240))
    draw.ellipse([CX+16,y+12,CX+48,y+44],fill=col)
    draw.text((CX+24,y+15),action_icons.get(action,"•"),font=F(16,bold=True),fill=WHITE)
    draw.text((CX+70,y+10),action,font=F(12,bold=True),fill=col)
    draw.text((CX+70,y+28),"Plugin event",font=F(10),fill=TEXT_LT)
    draw.text((CX+230,y+10),name,font=F(13,bold=True),fill=TEXT_DK)
    draw.text((CX+480,y+18),ver,font=F(12),fill=TEXT_MD)
    draw.text((CX+700,y+18),dt,font=F(12),fill=TEXT_MD)
    draw.text((CX+920,y+18),user,font=F(12),fill=TEXT_MD)

img.save(f"{OUT}/screenshot-6.png")
print("Screenshot 6 ✅")

# ── screenshot-7 : Health Scan ────────────────────────────────────────────────
img=Image.new("RGB",(SW,SH),BG); draw=ImageDraw.Draw(img)
wp_chrome(img,draw,"Health Scan")
CX=SB+20; CY=90; CW=SW-SB-40
draw.rectangle([CX,CY+34,CX+CW,CY+35],fill=BORDER)
CY+=55

# run scan button + last run
rrect(draw,(CX,CY,CX+CW,CY+56),6,CARD,BORDER,1)
draw.text((CX+20,CY+10),"Last scan: Today at 12:14  —  4 issues found",font=F(13),fill=TEXT_MD)
draw.text((CX+20,CY+30),"Scan covers: duplicate plugins, incompatibilities, outdated plugins, server config, pending updates",
          font=F(11),fill=TEXT_LT)
rrect(draw,(CX+CW-165,CY+12,CX+CW-15,CY+38),4,BLUE)
draw.text((CX+CW-155,CY+16),"Run New Scan",font=F(12,bold=True),fill=WHITE)

CY+=70
issues=[
    ("WARNING","Duplicate Functionality Detected",
     "You have 2 SEO plugins active: Yoast SEO and All in One SEO. Running both can cause conflicts and slow down your site.",
     AMBER),
    ("WARNING","Outdated Plugin",
     "Hello Dolly has not been updated in over 3 years (last update: 2021-03-04). It may have unpatched security issues.",
     AMBER),
    ("INFO","PHP Memory Limit",
     "Current memory limit is 128MB. WordPress recommends at least 256MB for reliable performance with your active plugins.",
     BLUE),
    ("OK","WordPress Core",
     "WordPress 6.6.1 is up to date. No pending updates.",
     GREEN),
]
for j,(sev,title,desc,col) in enumerate(issues):
    y=CY+j*110
    rrect(draw,(CX,y,CX+CW,y+100),6,CARD,col,2)
    draw.rectangle([CX,y,CX+5,y+100],fill=col)
    badge(draw,CX+14,y+14,sev,col)
    draw.text((CX+14,y+42),title,font=F(13,bold=True),fill=TEXT_DK)
    # word-wrap desc (simple)
    words=desc.split(); line=""; lines2=[]
    for w2 in words:
        test=line+(" " if line else "")+w2
        bb=draw.textbbox((0,0),test,font=F(11))
        if bb[2]-bb[0]>CW-50: lines2.append(line); line=w2
        else: line=test
    if line: lines2.append(line)
    for li,ln in enumerate(lines2[:2]):
        draw.text((CX+14,y+62+li*16),ln,font=F(11),fill=TEXT_MD)

img.save(f"{OUT}/screenshot-7.png")
print("Screenshot 7 ✅")
print("\nAll assets saved to .wordpress-org/")

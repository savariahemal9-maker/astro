-- Simple advice per category, written by the admin: what to do, what to avoid, upay (one per line)
SET NAMES utf8mb4;
ALTER TABLE prediction_categories
  ADD COLUMN dos_en TEXT NULL,
  ADD COLUMN dos_hi TEXT NULL,
  ADD COLUMN dos_gu TEXT NULL,
  ADD COLUMN donts_en TEXT NULL,
  ADD COLUMN donts_hi TEXT NULL,
  ADD COLUMN donts_gu TEXT NULL,
  ADD COLUMN upay_en TEXT NULL,
  ADD COLUMN upay_hi TEXT NULL,
  ADD COLUMN upay_gu TEXT NULL;
UPDATE prediction_categories SET dos_en = 'Plan your work and finish pending tasks first
Keep good relations with your boss and seniors
Learn a new skill that helps your job', dos_hi = 'अपना काम योजना से करें और पुराने काम पहले पूरे करें
बॉस और वरिष्ठों से अच्छे संबंध रखें
नौकरी में मदद करने वाला नया कौशल सीखें', dos_gu = 'કામનું આયોજન કરો અને બાકી કામ પહેલાં પૂરાં કરો
બોસ અને વરિષ્ઠો સાથે સારા સંબંધ રાખો
નોકરીમાં મદદરૂપ નવી આવડત શીખો', donts_en = 'Don\'t quit or change jobs in anger
Avoid arguments at the workplace', donts_hi = 'गुस्से में नौकरी न छोड़ें या न बदलें
कार्यस्थल पर बहस से बचें', donts_gu = 'ગુસ્સામાં નોકરી ન છોડો કે ન બદલો
કામના સ્થળે દલીલો ટાળો', upay_en = 'Offer water to the Sun every morning
Respect your father and elders', upay_hi = 'रोज़ सुबह सूर्य को जल चढ़ाएँ
पिता और बड़ों का सम्मान करें', upay_gu = 'રોજ સવારે સૂર્યને જળ ચઢાવો
પિતા અને વડીલોનું સન્માન કરો' WHERE slug = 'career';
UPDATE prediction_categories SET dos_en = 'Spend quality time with your partner
Talk openly and listen patiently
Show appreciation with small gestures', dos_hi = 'साथी के साथ अच्छा समय बिताएँ
खुलकर बात करें और धैर्य से सुनें
छोटे-छोटे काम से सराहना जताएँ', dos_gu = 'સાથી સાથે સારો સમય વિતાવો
ખુલ્લા મને વાત કરો અને ધીરજથી સાંભળો
નાની નાની બાબતોથી કદર બતાવો', donts_en = 'Don\'t doubt or check on your partner without reason
Avoid bringing up old fights', donts_hi = 'बिना कारण साथी पर शक न करें
पुराने झगड़े न दोहराएँ', donts_gu = 'કારણ વગર સાથી પર શંકા ન કરો
જૂના ઝઘડા ફરી ન ઉખેળો', upay_en = 'Wear clean, bright clothes and use a light perfume
On Fridays, gift something white or sweet to a girl or woman', upay_hi = 'साफ, चमकीले कपड़े पहनें और हल्की सुगंध लगाएँ
शुक्रवार को किसी कन्या या महिला को सफेद या मीठी चीज़ भेंट करें', upay_gu = 'સાફ, તેજસ્વી કપડાં પહેરો અને હળવી સુગંધ લગાવો
શુક્રવારે કોઈ કન્યા કે સ્ત્રીને સફેદ કે મીઠી વસ્તુ ભેટ આપો' WHERE slug = 'love';
UPDATE prediction_categories SET dos_en = 'Save a fixed part of your income
Keep a written record of spending
Pay old dues on time', dos_hi = 'आय का एक तय हिस्सा बचाएँ
खर्च का लिखित हिसाब रखें
पुराने बकाया समय पर चुकाएँ', dos_gu = 'આવકનો નક્કી ભાગ બચાવો
ખર્ચનો લેખિત હિસાબ રાખો
જૂની બાકી રકમ સમયસર ચૂકવો', donts_en = 'Don\'t lend large amounts without paperwork
Avoid impulse shopping and loans for luxuries', donts_hi = 'बिना लिखा-पढ़ी बड़ी रकम उधार न दें
शौक के लिए अचानक खरीदारी और कर्ज़ से बचें', donts_gu = 'લખાણ વગર મોટી રકમ ઉધાર ન આપો
શોખ માટે અચાનક ખરીદી અને લોન ટાળો', upay_en = 'Keep a small silver coin in your wallet or cash box
Donate some food or grain on Thursdays', upay_hi = 'बटुए या तिजोरी में चाँदी का छोटा सिक्का रखें
गुरुवार को कुछ अन्न या भोजन दान करें', upay_gu = 'પાકીટ કે તિજોરીમાં ચાંદીનો નાનો સિક્કો રાખો
ગુરુવારે થોડું અનાજ કે ભોજન દાન કરો' WHERE slug = 'finance';
UPDATE prediction_categories SET dos_en = 'Invest only money you can afford to lose
Research before every trade and set a stop-loss
Prefer long-term, diversified investing', dos_hi = 'केवल उतना पैसा लगाएँ जितना खोना सह सकें
हर सौदे से पहले जाँच करें और स्टॉप-लॉस रखें
लंबी अवधि और विविध निवेश को प्राथमिकता दें', dos_gu = 'ફક્ત એટલા જ પૈસા રોકો જેટલા ગુમાવવાનું પોસાય
દરેક સોદા પહેલાં તપાસ કરો અને સ્ટોપ-લોસ રાખો
લાંબા ગાળાના અને વૈવિધ્યસભર રોકાણને પસંદ કરો', donts_en = 'Don\'t borrow money to trade
Avoid tips, rumours and \'sure-shot\' schemes
Don\'t trade to recover losses quickly', donts_hi = 'उधार लेकर ट्रेडिंग न करें
टिप्स, अफवाहों और \'पक्के\' फायदे वाली योजनाओं से बचें
नुकसान जल्दी पूरा करने के लिए ट्रेड न करें', donts_gu = 'ઉધાર લઈને ટ્રેડિંગ ન કરો
ટિપ્સ, અફવાઓ અને \'પાક્કા\' ફાયદાની યોજનાઓ ટાળો
નુકસાન ઝડપથી પૂરું કરવા ટ્રેડ ન કરો', upay_en = 'Keep your investment papers in order and review them calmly
Feed birds or donate to the needy on Wednesdays', upay_hi = 'निवेश के कागज़ व्यवस्थित रखें और शांति से समीक्षा करें
बुधवार को पक्षियों को दाना डालें या ज़रूरतमंद को दान दें', upay_gu = 'રોકાણના કાગળો વ્યવસ્થિત રાખો અને શાંતિથી સમીક્ષા કરો
બુધવારે પક્ષીઓને ચણ નાખો અથવા જરૂરિયાતમંદને દાન આપો' WHERE slug = 'stock';
UPDATE prediction_categories SET dos_en = 'Practise regularly and warm up before playing
Focus on fitness, sleep and diet
Play as a team and follow your coach', dos_hi = 'नियमित अभ्यास करें और खेलने से पहले वार्म-अप करें
फिटनेस, नींद और खानपान पर ध्यान दें
टीम भावना से खेलें और कोच की बात मानें', dos_gu = 'નિયમિત પ્રેક્ટિસ કરો અને રમતાં પહેલાં વોર્મ-અપ કરો
ફિટનેસ, ઊંઘ અને ખોરાક પર ધ્યાન આપો
ટીમ ભાવનાથી રમો અને કોચની વાત માનો', donts_en = 'Don\'t play through an injury
Never bet money on matches', donts_hi = 'चोट के साथ न खेलें
मैचों पर कभी पैसा न लगाएँ', donts_gu = 'ઈજા સાથે ન રમો
મેચ પર ક્યારેય પૈસા ન લગાવો', upay_en = 'Do some exercise or a morning walk daily
Offer sweets at a Hanuman temple on Tuesdays', upay_hi = 'रोज़ थोड़ा व्यायाम या सुबह की सैर करें
मंगलवार को हनुमान मंदिर में मिठाई चढ़ाएँ', upay_gu = 'રોજ થોડી કસરત કે સવારની ચાલ કરો
મંગળવારે હનુમાન મંદિરમાં મીઠાઈ ચઢાવો' WHERE slug = 'sports';
UPDATE prediction_categories SET dos_en = 'Sleep on time and wake up early
Eat fresh, simple food and drink enough water
Walk or exercise daily', dos_hi = 'समय पर सोएँ और जल्दी उठें
ताज़ा, सादा भोजन करें और पर्याप्त पानी पिएँ
रोज़ टहलें या व्यायाम करें', dos_gu = 'સમયસર સૂઈ જાઓ અને વહેલા ઊઠો
તાજું, સાદું ભોજન લો અને પૂરતું પાણી પીઓ
રોજ ચાલો અથવા કસરત કરો', donts_en = 'Don\'t ignore small symptoms — see a doctor
Avoid junk food, smoking and alcohol', donts_hi = 'छोटे लक्षणों को अनदेखा न करें — डॉक्टर को दिखाएँ
जंक फूड, धूम्रपान और शराब से बचें', donts_gu = 'નાના લક્ષણોને અવગણશો નહીં — ડૉક્ટરને બતાવો
જંક ફૂડ, ધૂમ્રપાન અને દારૂ ટાળો', upay_en = 'Do deep breathing or pranayama for 10 minutes daily
Donate medicines or food to the sick when you can', upay_hi = 'रोज़ 10 मिनट गहरी साँस या प्राणायाम करें
संभव हो तो बीमारों को दवा या भोजन दान करें', upay_gu = 'રોજ 10 મિનિટ ઊંડા શ્વાસ કે પ્રાણાયામ કરો
શક્ય હોય તો બીમારોને દવા કે ભોજન દાન કરો' WHERE slug = 'health';
UPDATE prediction_categories SET dos_en = 'Make a study timetable and stick to it
Revise daily and practise old papers
Ask teachers when you have doubts', dos_hi = 'पढ़ाई का समय-सारणी बनाएँ और उसका पालन करें
रोज़ दोहराएँ और पुराने प्रश्नपत्र हल करें
शंका हो तो शिक्षक से पूछें', dos_gu = 'અભ્યાસનું સમયપત્રક બનાવો અને તેનું પાલન કરો
રોજ પુનરાવર્તન કરો અને જૂના પેપર ઉકેલો
શંકા હોય તો શિક્ષકને પૂછો', donts_en = 'Don\'t leave studies for the last minute
Avoid too much phone and social media while studying', donts_hi = 'पढ़ाई आखिरी समय पर न छोड़ें
पढ़ते समय ज़्यादा फोन और सोशल मीडिया से बचें', donts_gu = 'અભ્યાસ છેલ્લી ઘડી માટે ન છોડો
ભણતી વખતે વધુ પડતો ફોન અને સોશિયલ મીડિયા ટાળો', upay_en = 'Respect your teachers and elders
Keep your study table clean and face east or north while studying', upay_hi = 'शिक्षकों और बड़ों का सम्मान करें
पढ़ाई की मेज़ साफ रखें और पूर्व या उत्तर की ओर मुख करके पढ़ें', upay_gu = 'શિક્ષકો અને વડીલોનું સન્માન કરો
અભ્યાસનું ટેબલ સાફ રાખો અને પૂર્વ કે ઉત્તર તરફ મુખ રાખીને ભણો' WHERE slug = 'education';
UPDATE prediction_categories SET dos_en = 'Plan and book in advance
Keep documents and copies safe
Inform family about your travel plan', dos_hi = 'पहले से योजना बनाकर बुकिंग करें
दस्तावेज़ और उनकी कॉपी सुरक्षित रखें
परिवार को यात्रा की जानकारी दें', dos_gu = 'અગાઉથી આયોજન કરીને બુકિંગ કરો
દસ્તાવેજો અને તેની નકલ સુરક્ષિત રાખો
પરિવારને મુસાફરીની જાણ કરો', donts_en = 'Don\'t travel in a hurry or late at night if avoidable
Avoid carrying too much cash', donts_hi = 'जहाँ तक हो सके जल्दबाज़ी में या देर रात यात्रा न करें
ज़्यादा नकद साथ न रखें', donts_gu = 'શક્ય હોય તો ઉતાવળમાં કે મોડી રાત્રે મુસાફરી ન કરો
વધુ રોકડ સાથે ન રાખો', upay_en = 'Carry a small bottle of water from home
Feed a dog before a long journey', upay_hi = 'घर से पानी की छोटी बोतल साथ रखें
लंबी यात्रा से पहले कुत्ते को रोटी खिलाएँ', upay_gu = 'ઘરેથી પાણીની નાની બોટલ સાથે રાખો
લાંબી મુસાફરી પહેલાં કૂતરાને રોટલી ખવડાવો' WHERE slug = 'travel';

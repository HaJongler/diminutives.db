This project is a database of common [English diminutives](https://secure.wikimedia.org/wikipedia/en/wiki/Diminutive#English) (nicknames and shortened forms) of formal [given names](https://secure.wikimedia.org/wikipedia/en/wiki/Given_name). It is useful whenever you need to search among lists of people's names for matches in a way that is tolerant to common colloquial variation. For example, "Daniel" may appear in databases as "Danny" or "Dan", and "Catherine" as "Cathie" or "Kate".

## Format of the CSV files
Each line of `male_diminutives.csv` and `female_diminutives.csv` consists of a formal given name followed by common diminutives of that name. For example, the following line from `male_diminutives.csv` indicates that "Nat" and "Nate" are common diminutives of the given name "Nathaniel":

	Nathaniel,Nat,Nate

The CSV files are encoded in [UTF-8](https://secure.wikimedia.org/wikipedia/en/wiki/UTF-8).

## Special exceptions
You should be aware of the following special case which cannot be added to the databases:

* When a man's initials are J.E.B., he may go by [Jeb](https://secure.wikimedia.org/wiktionary/en/wiki/Jeb).

## License
Scripts are licensed under the terms of the [GNU General Public License version 3](http://www.gnu.org/licenses/gpl.html) or any later version.

The data in [`male_diminutives.csv`](https://github.com/dtrebbien/diminutives.db/blob/master/male_diminutives.csv) and [`female_diminutives.csv`](https://github.com/dtrebbien/diminutives.db/blob/master/female_diminutives.csv) is Public Domain.
